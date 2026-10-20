<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\PrivateFiles;
use App\Social\SessionStore;
use App\Social\XPublisher;
use Crab\App;
use Crab\Config\Config;
use Crab\HttpClient\FakeClient;

// An assertion evaluates a condition rather than selecting a behavior.
// @mago-expect lint:no-boolean-flag-parameter
function x_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

new App(new Config(['postslop' => [
    'x_url' => 'https://x.fixture.test',
    'x_bundle_url' => 'https://assets.fixture.test/main.js',
]]));

$directory = sys_get_temp_dir() . '/postslop-x-rejections-' . bin2hex(random_bytes(6));
$sessions = new SessionStore($directory);
$sessions->write('x', [
    'cookies' => [
        ['name' => 'auth_token', 'value' => 'fixture-auth', 'domain' => '.x.com', 'expires' => time() + 3600],
        ['name' => 'ct0', 'value' => 'fixture-csrf', 'domain' => '.x.com', 'expires' => -1],
        ['name' => '__cf_bm', 'value' => 'expired-fixture-cookie', 'domain' => '.x.com', 'expires' => time() - 3600],
    ],
    'origins' => [],
]);
PrivateFiles::json($directory . '/manifest.json', ['accounts' => ['x' => ['handle' => '@tester']]]);

// These are the same observed website-response shapes and synthetic identity as tests/fakes/x.cjs.
$state = [
    'session' => ['user_id' => '123'],
    'entities' => ['users' => ['entities' => ['123' => ['screen_name' => 'tester']]]],
    'featureSwitch' => ['defaultConfig' => ['fixture_enabled' => ['value' => true]], 'user' => ['config' => []]],
];
$home = '<script>window.__INITIAL_STATE__=' . json_encode($state) . ';window.__META_DATA__={};</script>';
$bundle = 'const token="Bearer AAAAAfixture";const operation={queryId:"fixtureCreate",operationName:"CreateTweet",operationType:"mutation",metadata:{featureSwitches:["fixture_enabled"],fieldToggles:[]}};';
$limitMessage = 'Authorization: You have reached your daily limit for sending Tweets and messages. Please try again later. (344)';
$automationMessage = 'Authorization: This request looks like it might be automated. Please try again later. (226)';
$cases = [
    [200, 344, $limitMessage,      'failed'],
    [200, 226, $automationMessage, 'failed'],
    [500, 344, $limitMessage,      'unknown'],
    [503, 226, $automationMessage, 'unknown'],
];

try {
    foreach ($cases as [$httpStatus, $errorCode, $message, $expected]) {
        $http = new FakeClient([
            'GET https://x.fixture.test/home' => $home,
            'GET https://assets.fixture.test/main.js' => $bundle,
            'POST https://x.fixture.test/i/api/graphql/fixtureCreate/CreateTweet' => FakeClient::response($httpStatus, [
                'errors' => [[
                    'code' => $errorCode,
                    'message' => "<b>{$message}</b>\n\0fixture-auth fixture-csrf Bearer AAAAAfixture",
                ]],
            ]),
        ], redirects: 0);
        $publisher = new XPublisher($http, $sessions);
        $result = $publisher->publish('fixture-only', 'A fixture post that never reaches X.', []);
        x_check($result['status'] === $expected, "HTTP {$httpStatus}/{$errorCode} had the wrong outcome");
        x_check(count($http->sent('POST *')) === 1, "HTTP {$httpStatus}/{$errorCode} retried the submission");

        foreach ($http->sent('https://x.fixture.test/*') as $request) {
            $cookies = $request->getHeaderLine('Cookie');
            x_check(str_contains($cookies, 'auth_token=fixture-auth'), 'The active auth cookie was omitted');
            x_check(str_contains($cookies, 'ct0=fixture-csrf'), 'The session CSRF cookie was omitted');
            x_check(
                !str_contains($cookies, '__cf_bm') && !str_contains($cookies, 'expired-fixture-cookie'),
                'An expired cookie was forwarded',
            );
        }
        $publicRequest = $http->sent('GET https://assets.fixture.test/main.js')[0];
        x_check(
            !$publicRequest->hasHeader('Cookie') && !$publicRequest->hasHeader('Authorization'),
            'A public asset received credentials',
        );

        if ($httpStatus !== 200) {
            continue;
        }
        x_check(str_contains($result['error'], 'HTTP 200, code ' . $errorCode), 'The diagnostic lost its status/code');
        x_check(str_contains($result['error'], $message), 'The upstream explanation was lost');
        foreach (['fixture-auth', 'fixture-csrf', 'AAAAAfixture', '<b>', "\0", "\n"] as $unsafe) {
            x_check(
                !str_contains($result['error'], $unsafe),
                'The diagnostic leaked credentials, markup or control characters',
            );
        }
    }
    echo
        "X rejection checks passed: 344/226 diagnostics, credential redaction, expired cookies, public assets, and uncertain 5xx without retries.\n";
} finally {
    unlink($directory . '/x.json');
    unlink($directory . '/manifest.json');
    rmdir($directory);
}
