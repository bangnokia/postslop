import { execFileSync } from "node:child_process";
import { mkdirSync } from "node:fs";

const target = `${process.arch === "arm64" ? "arm64" : "x86_64"}-apple-macosx13.0`;
mkdirSync("assets", { recursive: true });
execFileSync(
  "swiftc",
  [
    "-target",
    target,
    "-O",
    "native/image-helper.swift",
    "-o",
    "assets/image-helper",
  ],
  { stdio: "inherit" },
);
execFileSync(
  "/usr/bin/codesign",
  ["--force", "--sign", "-", "assets/image-helper"],
  { stdio: "inherit" },
);
