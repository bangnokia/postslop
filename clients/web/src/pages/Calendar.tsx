import { useEffect, useLayoutEffect, useRef, useState } from "react";
import { api, inFlight, postTime, type Post } from "../api";
import { Icon } from "../components/icons";
import { PageHeader, useShell } from "../shell";
import { PlatformIcon, usePolling, usePostsChanged } from "../ui";

type View = "month" | "week";

const WEEKDAYS = Array.from({ length: 7 }, (_, index) => new Date(2024, 0, 1 + index).toLocaleDateString(undefined, { weekday: "short" }));

const startOfDay = (date: Date) => new Date(date.getFullYear(), date.getMonth(), date.getDate());
const addDays = (date: Date, days: number) => new Date(date.getFullYear(), date.getMonth(), date.getDate() + days);
// Weeks start on Monday.
const startOfWeek = (date: Date) => addDays(startOfDay(date), -((date.getDay() + 6) % 7));
const dayKey = (date: Date) => `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}`;
const sameDay = (a: Date, b: Date) => dayKey(a) === dayKey(b);
const timeLabel = (date: Date) => date.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" });

// The days a view shows: six whole weeks around the month, or one week.
function range(view: View, anchor: Date): Date[] {
  const first = view === "month" ? startOfWeek(new Date(anchor.getFullYear(), anchor.getMonth(), 1)) : startOfWeek(anchor);
  return Array.from({ length: view === "month" ? 42 : 7 }, (_, index) => addDays(first, index));
}

// A sensible time to suggest for a new post on a day: 09:00, or the next whole hour when that has passed.
function suggestedTime(day: Date, hour = 9): Date | undefined {
  const at = new Date(day.getFullYear(), day.getMonth(), day.getDate(), hour);
  if (at.getTime() > Date.now() + 5 * 60_000) return at;
  const next = new Date();
  next.setMinutes(0, 0, 0);
  next.setHours(next.getHours() + 1);
  return sameDay(next, day) ? next : undefined;
}

export function Calendar({ view }: { view: View }) {
  const shell = useShell();
  const [anchor, setAnchor] = useState(() => startOfDay(new Date()));
  const [posts, setPosts] = useState<Post[]>();
  const [error, setError] = useState<string>();
  const days = range(view, anchor);
  const from = days[0];
  const to = addDays(days[days.length - 1], 1);

  const load = () => {
    let live = true;
    api
      .between(from, to)
      .then((value) => {
        if (!live) return;
        setPosts(value);
        setError(undefined);
      })
      .catch((reason: Error) => live && setError(reason.message));
    return () => {
      live = false;
    };
  };
  useEffect(load, [from.getTime(), to.getTime()]);
  usePostsChanged(load);
  usePolling(Boolean(posts?.some(inFlight)), load, 2000);

  const byDay = new Map<string, Post[]>();
  for (const post of posts ?? []) {
    const key = dayKey(new Date(postTime(post)));
    byDay.set(key, [...(byDay.get(key) ?? []), post]);
  }

  const move = (direction: number) => setAnchor((current) => (view === "month" ? new Date(current.getFullYear(), current.getMonth() + direction, 1) : addDays(current, 7 * direction)));
  const title =
    view === "month"
      ? anchor.toLocaleDateString(undefined, { month: "long", year: "numeric" })
      : `${days[0].toLocaleDateString(undefined, { month: "short", day: "numeric" })} – ${days[6].toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" })}`;
  const compose = (at?: Date) => at && shell.compose({ at });

  return (
    <>
      <PageHeader title={title}>
        <div className="calendar-nav">
          <button type="button" className="btn btn-secondary btn-sm" onClick={() => setAnchor(startOfDay(new Date()))}>
            Today
          </button>
          <button type="button" className="btn btn-ghost btn-icon btn-sm" aria-label={view === "month" ? "Previous month" : "Previous week"} onClick={() => move(-1)}>
            <Icon name="left" />
          </button>
          <button type="button" className="btn btn-ghost btn-icon btn-sm" aria-label={view === "month" ? "Next month" : "Next week"} onClick={() => move(1)}>
            <Icon name="right" />
          </button>
        </div>
        <nav className="segmented" aria-label="Calendar view">
          <a href="#/calendar" aria-current={view === "month" ? "page" : undefined}>
            Month
          </a>
          <a href="#/calendar/week" aria-current={view === "week" ? "page" : undefined}>
            Week
          </a>
        </nav>
        <button type="button" className="btn btn-primary" onClick={() => shell.compose()}>
          <Icon name="plus" /> <span className="hide-sm">New post</span>
        </button>
      </PageHeader>
      <div className="page-body page-body-calendar">
        {error && <p className="error-text">{error}</p>}
        {view === "month" ? (
          <MonthGrid
            days={days}
            month={anchor.getMonth()}
            byDay={byDay}
            loading={!posts}
            onCompose={(day) => compose(suggestedTime(day))}
            onOpen={shell.openPost}
            onMore={(day) => {
              setAnchor(day);
              window.location.hash = "#/calendar/week";
            }}
          />
        ) : (
          <WeekGrid days={days} byDay={byDay} onCompose={(day, hour) => compose(suggestedTime(day, hour))} onOpen={shell.openPost} />
        )}
      </div>
    </>
  );
}

function Chip({ post, onOpen, compact = false }: { post: Post; onOpen: (id: string) => void; compact?: boolean }) {
  const at = new Date(postTime(post));
  return (
    <button
      type="button"
      className="cal-chip"
      data-status={post.status}
      title={post.text}
      onClick={(event) => {
        event.stopPropagation();
        onOpen(post.id);
      }}
    >
      <span className="cal-chip-time">{timeLabel(at)}</span>
      {!compact && (
        <span className="cal-chip-icons">
          {post.platforms.map((platform) => (
            <PlatformIcon key={platform} platform={platform} size={11} />
          ))}
        </span>
      )}
      <span className="cal-chip-text">{post.text || "Images only"}</span>
    </button>
  );
}

function MonthGrid({
  days,
  month,
  byDay,
  loading,
  onCompose,
  onOpen,
  onMore,
}: {
  days: Date[];
  month: number;
  byDay: Map<string, Post[]>;
  loading: boolean;
  onCompose: (day: Date) => void;
  onOpen: (id: string) => void;
  onMore: (day: Date) => void;
}) {
  const today = startOfDay(new Date());
  return (
    <div className="month" aria-busy={loading}>
      <div className="month-head">
        {WEEKDAYS.map((name) => (
          <div key={name}>{name}</div>
        ))}
      </div>
      <div className="month-grid">
        {days.map((day) => {
          const list = byDay.get(dayKey(day)) ?? [];
          const past = day.getTime() < today.getTime();
          const open = !past && suggestedTime(day) !== undefined;
          return (
            <div
              key={dayKey(day)}
              className="month-cell"
              data-outside={day.getMonth() !== month}
              data-today={sameDay(day, today)}
              data-past={past}
              onClick={() => {
                // On a phone the day shows only dots, so a tap opens its week instead.
                if (list.length > 0 && window.matchMedia("(max-width: 700px)").matches) onMore(day);
                else if (open) onCompose(day);
              }}
            >
              <div className="month-date">
                <span>{day.getDate()}</span>
                {open && (
                  <button
                    type="button"
                    className="month-add"
                    aria-label={`New post on ${day.toLocaleDateString(undefined, { dateStyle: "long" })}`}
                    onClick={(event) => {
                      event.stopPropagation();
                      onCompose(day);
                    }}
                  >
                    <Icon name="plus" size={14} />
                  </button>
                )}
              </div>
              <div className="month-posts">
                {list.slice(0, 3).map((post) => (
                  <Chip key={post.id} post={post} onOpen={onOpen} />
                ))}
                {list.length > 3 && (
                  <button
                    type="button"
                    className="month-more"
                    onClick={(event) => {
                      event.stopPropagation();
                      onMore(day);
                    }}
                  >
                    +{list.length - 3} more
                  </button>
                )}
              </div>
              {list.length > 0 && (
                <div className="month-dots" aria-hidden="true">
                  {list.slice(0, 4).map((post) => (
                    <span key={post.id} data-status={post.status} />
                  ))}
                </div>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}

const HOURS = Array.from({ length: 24 }, (_, hour) => hour);

function WeekGrid({ days, byDay, onCompose, onOpen }: { days: Date[]; byDay: Map<string, Post[]>; onCompose: (day: Date, hour: number) => void; onOpen: (id: string) => void }) {
  const scroller = useRef<HTMLDivElement>(null);
  const today = startOfDay(new Date());
  const now = new Date();
  // Open on the working day, or earlier when a post this week is earlier.
  const first = Math.min(7, ...[...byDay.values()].flat().map((post) => new Date(postTime(post)).getHours()));
  useLayoutEffect(() => {
    const row = scroller.current?.querySelectorAll<HTMLElement>(".week-row")[first];
    if (scroller.current && row) scroller.current.scrollTop = Math.max(0, row.offsetTop - 12);
  }, [first]);
  return (
    <div className="week">
      <div className="week-head">
        <div />
        {days.map((day) => (
          <div key={dayKey(day)} className="week-day" data-today={sameDay(day, today)}>
            <span>{day.toLocaleDateString(undefined, { weekday: "short" })}</span>
            <strong>{day.getDate()}</strong>
          </div>
        ))}
      </div>
      <div className="week-body" ref={scroller}>
        <div className="week-grid">
          {HOURS.map((hour) => (
            <div key={hour} className="week-row">
              <div className="week-hour">{hour === 0 ? "" : timeLabel(new Date(2024, 0, 1, hour))}</div>
              {days.map((day) => {
                const slot = new Date(day.getFullYear(), day.getMonth(), day.getDate(), hour + 1);
                const open = slot.getTime() > Date.now();
                const list = (byDay.get(dayKey(day)) ?? []).filter((post) => new Date(postTime(post)).getHours() === hour);
                return (
                  <div key={dayKey(day)} className="week-cell" data-past={!open} data-today={sameDay(day, today)} onClick={() => open && onCompose(day, hour)}>
                    {sameDay(day, today) && now.getHours() === hour && <span className="now-line" style={{ top: `${(now.getMinutes() / 60) * 100}%` }} />}
                    {list.map((post) => (
                      <Chip key={post.id} post={post} onOpen={onOpen} compact />
                    ))}
                  </div>
                );
              })}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

