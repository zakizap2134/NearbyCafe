import { useEffect, useMemo, useState } from "react";
import { api } from "../api";
import { money } from "./workflow";

const PERIOD_OPTIONS = [
  { id: "7d", label: "7 Days", eyebrow: "LAST 7 DAYS", title: "7-Day Sales Comparison", short: "7-Day", badge: "7d" },
  { id: "14d", label: "14 Days", eyebrow: "LAST 14 DAYS", title: "14-Day Sales Comparison", short: "14-Day", badge: "14d" },
  { id: "30d", label: "30 Days", eyebrow: "LAST 30 DAYS", title: "30-Day Sales Comparison", short: "30-Day", badge: "30d" },
  { id: "month", label: "This Month", eyebrow: "MONTH TO DATE", title: "Monthly Sales Comparison", short: "Monthly", badge: "MTD" },
  { id: "custom", label: "Custom Range", eyebrow: "CUSTOM DATE WINDOW", title: "Custom Period Sales Comparison", short: "Period", badge: "Custom" },
];

function isIsoDate(str) {
  return typeof str === "string" && /^\d{4}-\d{2}-\d{2}$/.test(str);
}

function getManilaToday() {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Manila",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
}

function offsetIsoDate(baseIso, offsetDays) {
  const clean = isIsoDate(baseIso) ? baseIso : getManilaToday();
  const [y, m, d] = clean.split("-").map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d + offsetDays));
  return dt.toISOString().slice(0, 10);
}

function enumerateIsoDates(startIso, endIso, maxDays = 90) {
  const cleanStart = isIsoDate(startIso) ? startIso : getManilaToday();
  const cleanEnd = isIsoDate(endIso) ? endIso : cleanStart;
  const [sY, sM, sD] = (cleanStart <= cleanEnd ? cleanStart : cleanEnd).split("-").map(Number);
  const [eY, eM, eD] = (cleanStart <= cleanEnd ? cleanEnd : cleanStart).split("-").map(Number);
  const startUtc = Date.UTC(sY, sM - 1, sD);
  const endUtc = Date.UTC(eY, eM - 1, eD);
  const totalDiff = Math.floor((endUtc - startUtc) / 86400000);
  const clampedDiff = Math.min(Math.max(0, totalDiff), maxDays - 1);
  const startAdjustedUtc = endUtc - clampedDiff * 86400000;

  const dates = [];
  for (let i = 0; i <= clampedDiff; i++) {
    const dt = new Date(startAdjustedUtc + i * 86400000);
    dates.push(dt.toISOString().slice(0, 10));
  }
  return dates;
}

function formatDayShort(isoDate) {
  if (!isIsoDate(isoDate)) return { weekday: isoDate, monthDay: "" };
  const [y, m, d] = isoDate.split("-").map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d));
  const weekday = new Intl.DateTimeFormat("en-US", {
    weekday: "short",
    timeZone: "UTC",
  }).format(dt);
  const monthDay = new Intl.DateTimeFormat("en-US", {
    month: "short",
    day: "numeric",
    timeZone: "UTC",
  }).format(dt);
  return { weekday, monthDay };
}

function formatDayFull(isoDate) {
  if (!isIsoDate(isoDate)) return String(isoDate || "");
  const [y, m, d] = isoDate.split("-").map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d));
  return new Intl.DateTimeFormat("en-US", {
    weekday: "short",
    month: "short",
    day: "numeric",
    year: "numeric",
    timeZone: "UTC",
  }).format(dt);
}

function compactPeso(value) {
  const num = Number(value || 0);
  if (num === 0) return "₱0";
  if (num >= 10000) {
    return `₱${(num / 1000).toFixed(1)}k`;
  }
  return `₱${num % 1 === 0 ? num.toLocaleString("en-PH") : num.toFixed(0)}`;
}

export default function SalesTrendChart({ sales, token, onOpenReports }) {
  const anchorDate = isIsoDate(sales?.date) ? sales.date : getManilaToday();
  const [periodId, setPeriodId] = useState("7d");
  const [customFrom, setCustomFrom] = useState(() => offsetIsoDate(anchorDate, -13));
  const [customTo, setCustomTo] = useState(() => anchorDate);
  const [remoteDaily, setRemoteDaily] = useState(null);
  const [loadingRange, setLoadingRange] = useState(false);
  const [selectedIndex, setSelectedIndex] = useState(6);

  const rangeConfig = useMemo(() => {
    const preset = PERIOD_OPTIONS.find((p) => p.id === periodId) || PERIOD_OPTIONS[0];
    let startDate = offsetIsoDate(anchorDate, -6);
    let endDate = anchorDate;

    if (periodId === "14d") {
      startDate = offsetIsoDate(anchorDate, -13);
      endDate = anchorDate;
    } else if (periodId === "30d") {
      startDate = offsetIsoDate(anchorDate, -29);
      endDate = anchorDate;
    } else if (periodId === "month") {
      const monthFirst = `${anchorDate.slice(0, 7)}-01`;
      // Ensure at least a 2-day window on the 1st of the month so comparison works cleanly
      startDate = monthFirst === anchorDate ? offsetIsoDate(anchorDate, -6) : monthFirst;
      endDate = anchorDate;
    } else if (periodId === "custom") {
      const f = isIsoDate(customFrom) ? customFrom : offsetIsoDate(anchorDate, -6);
      const t = isIsoDate(customTo) ? customTo : anchorDate;
      startDate = f <= t ? f : t;
      endDate = f <= t ? t : f;
    }

    const dateList = enumerateIsoDates(startDate, endDate, 90);
    return {
      ...preset,
      startDate: dateList[0] || startDate,
      endDate: dateList[dateList.length - 1] || endDate,
      dateList,
    };
  }, [anchorDate, customFrom, customTo, periodId]);

  useEffect(() => {
    if (periodId === "7d" && Array.isArray(sales?.weekly) && sales.weekly.length > 0) {
      setRemoteDaily(null);
      setLoadingRange(false);
      return;
    }
    if (!token) return;
    let cancelled = false;
    setLoadingRange(true);
    api("management/reports", {
      token,
      query: { from: rangeConfig.startDate, to: rangeConfig.endDate, page: 1 },
    })
      .then((res) => {
        if (!cancelled && Array.isArray(res?.daily)) {
          setRemoteDaily(res.daily);
        }
      })
      .catch(() => {
        /* Fallback to inline weekly data if reports endpoint is unreachable */
      })
      .finally(() => {
        if (!cancelled) {
          setLoadingRange(false);
        }
      });
    return () => {
      cancelled = true;
    };
  }, [periodId, rangeConfig.endDate, rangeConfig.startDate, sales?.weekly, token]);

  const days = useMemo(() => {
    const map = new Map();
    if (Array.isArray(sales?.weekly)) {
      sales.weekly.forEach((row) => {
        if (row?.date) {
          map.set(String(row.date), {
            date: String(row.date),
            count: Number(row.count || 0),
            gross: Number(row.gross || 0),
            net: Number(row.net || 0),
            vat: Number(row.vat || 0),
          });
        }
      });
    }
    if (Array.isArray(remoteDaily)) {
      remoteDaily.forEach((row) => {
        if (row?.date) {
          map.set(String(row.date), {
            date: String(row.date),
            count: Number(row.count || 0),
            gross: Number(row.gross || 0),
            net: Number(row.net || 0),
            vat: Number(row.vat || 0),
          });
        }
      });
    }

    if (sales?.totals) {
      map.set(anchorDate, {
        date: anchorDate,
        count: Number(sales.totals.count || 0),
        gross: Number(sales.totals.gross || 0),
        net: Number(sales.totals.net || 0),
        vat: Number(sales.totals.vat || 0),
      });
    }

    return rangeConfig.dateList.map((dStr) => {
      const existing = map.get(dStr);
      const { weekday, monthDay } = formatDayShort(dStr);
      return {
        date: dStr,
        weekday,
        monthDay,
        isToday: dStr === anchorDate,
        count: existing ? existing.count : 0,
        gross: existing ? existing.gross : 0,
        net: existing ? existing.net : 0,
        vat: existing ? existing.vat : 0,
      };
    });
  }, [anchorDate, rangeConfig.dateList, remoteDaily, sales]);

  useEffect(() => {
    setSelectedIndex(Math.max(0, days.length - 1));
  }, [days.length, rangeConfig.startDate, rangeConfig.endDate]);

  const metrics = useMemo(() => {
    const numDays = Math.max(1, days.length);
    const totalGross = days.reduce((acc, d) => acc + d.gross, 0);
    const totalOrders = days.reduce((acc, d) => acc + d.count, 0);
    const dailyAvg = totalGross / numDays;
    const latestEntry = days[days.length - 1] || { gross: 0, count: 0, isToday: true };
    const prevEntry = days.length >= 2 ? days[days.length - 2] : { gross: 0, count: 0 };
    const diffLatestPrev = latestEntry.gross - prevEntry.gross;
    const compareNoun = latestEntry.isToday ? "yesterday" : "prior day";

    let diffPctLabel = `No change vs ${compareNoun}`;
    let diffTone = "neutral";
    if (diffLatestPrev > 0) {
      diffTone = "positive";
      if (prevEntry.gross > 0) {
        const pct = ((diffLatestPrev / prevEntry.gross) * 100).toFixed(1);
        diffPctLabel = `+${pct}% vs ${compareNoun}`;
      } else {
        diffPctLabel = `Up from ₱0 ${compareNoun}`;
      }
    } else if (diffLatestPrev < 0) {
      diffTone = "negative";
      if (prevEntry.gross > 0) {
        const pct = ((diffLatestPrev / prevEntry.gross) * 100).toFixed(1);
        diffPctLabel = `${pct}% vs ${compareNoun}`;
      } else {
        diffPctLabel = `Down vs ${compareNoun}`;
      }
    }

    let peakDay = days[0];
    days.forEach((d) => {
      if (d.gross >= (peakDay?.gross || 0)) {
        peakDay = d;
      }
    });

    const maxGross = Math.max(...days.map((d) => d.gross), 100);
    return {
      numDays,
      totalGross,
      totalOrders,
      dailyAvg,
      latestEntry,
      prevEntry,
      diffLatestPrev,
      diffPctLabel,
      diffTone,
      peakDay,
      maxGross,
    };
  }, [days]);

  const safeSelectedIndex = Math.min(Math.max(0, selectedIndex), Math.max(0, days.length - 1));
  const selectedDay = days[safeSelectedIndex] || days[days.length - 1];
  const vsAvgDiff = selectedDay ? selectedDay.gross - metrics.dailyAvg : 0;

  // SVG coordinate constants
  const chartW = 720;
  const chartH = 228;
  const padLeft = 58;
  const padRight = 22;
  const padTop = 28;
  const padBottom = 48;
  const plotW = chartW - padLeft - padRight;
  const plotH = chartH - padTop - padBottom;
  const numCols = Math.max(1, days.length);
  const colW = plotW / numCols;
  const barW = Math.max(6, Math.min(44, colW * 0.56));
  const barRadius = barW >= 16 ? 5 : 3;

  // Label density step so 14-day and 30-day charts remain clean and legible
  const labelStep =
    numCols <= 9
      ? 1
      : numCols <= 16
        ? 2
        : numCols <= 24
          ? 3
          : numCols <= 35
            ? 5
            : Math.ceil(numCols / 7);

  const points = days.map((d, idx) => {
    const cx = padLeft + idx * colW + colW / 2;
    const ratio = metrics.maxGross > 0 ? d.gross / metrics.maxGross : 0;
    const barH = Math.max(d.gross > 0 ? 6 : 2, Math.round(ratio * plotH));
    const cy = padTop + plotH - barH;
    return { cx, cy, barH, ratio, d, idx };
  });

  const polylinePoints = points.map((p) => `${p.cx},${p.cy}`).join(" ");
  const firstDay = days[0];
  const lastDay = days[days.length - 1];
  const shortPeriodPrefix =
    periodId === "custom" ? `${metrics.numDays}-Day` : rangeConfig.short;
  const badgePeriodSuffix =
    periodId === "custom" ? `${metrics.numDays}d` : rangeConfig.badge;

  return (
    <section className="panel sales-trend-panel" aria-label="Sales visual comparison graph">
      <div className="sales-trend-header">
        <div>
          <p className="eyebrow">
            REVENUE TRAJECTORY · {rangeConfig.eyebrow}
            {loadingRange ? " · UPDATING…" : ""}
          </p>
          <h2>{rangeConfig.title}</h2>
          <p className="subtle">
            Daily gross revenue and order volume from {firstDay?.monthDay || firstDay?.date} to{" "}
            {lastDay?.monthDay || lastDay?.date} ({metrics.numDays} days). Select any bar to inspect day-over-day differences.
          </p>
        </div>

        <div className="sales-trend-controls">
          <div className="sales-period-selector" role="group" aria-label="Sales graph timeframe">
            {PERIOD_OPTIONS.map((opt) => (
              <button
                key={opt.id}
                type="button"
                className={`sales-period-btn${periodId === opt.id ? " active" : ""}`}
                aria-pressed={periodId === opt.id}
                onClick={() => setPeriodId(opt.id)}
              >
                {opt.label}
              </button>
            ))}
          </div>

          {onOpenReports && (
            <button type="button" className="compact-btn" onClick={onOpenReports}>
              Full period reports →
            </button>
          )}
        </div>
      </div>

      {periodId === "custom" && (
        <div className="sales-custom-range-bar" role="region" aria-label="Custom date range">
          <label className="sales-custom-field">
            <span>From</span>
            <input
              type="date"
              value={customFrom}
              max={customTo || anchorDate}
              onChange={(e) => setCustomFrom(e.target.value)}
            />
          </label>
          <label className="sales-custom-field">
            <span>To</span>
            <input
              type="date"
              value={customTo}
              min={customFrom}
              max={anchorDate}
              onChange={(e) => setCustomTo(e.target.value)}
            />
          </label>
          <span className="sales-custom-hint">
            Showing {metrics.numDays} calendar {metrics.numDays === 1 ? "day" : "days"}
          </span>
        </div>
      )}

      <div className="sales-trend-kpis">
        <div className="trend-kpi-card">
          <span className="trend-kpi-label">{shortPeriodPrefix} Gross Total</span>
          <strong className="trend-kpi-value">{money(metrics.totalGross)}</strong>
          <span className="trend-kpi-sub">{metrics.totalOrders} completed orders</span>
        </div>

        <div className="trend-kpi-card">
          <span className="trend-kpi-label">
            {metrics.latestEntry.isToday ? "Today vs Yesterday" : "Latest vs Prior Day"}
          </span>
          <strong className={`trend-kpi-value tone-${metrics.diffTone}`}>
            {metrics.diffLatestPrev > 0 ? "+" : ""}
            {money(metrics.diffLatestPrev)}
          </strong>
          <span className="trend-kpi-sub">
            <span className={`trend-delta-pill tone-${metrics.diffTone}`}>
              {metrics.diffTone === "positive"
                ? "UP"
                : metrics.diffTone === "negative"
                  ? "DOWN"
                  : "STEADY"}
            </span>{" "}
            {metrics.diffPctLabel}
          </span>
        </div>

        <div className="trend-kpi-card">
          <span className="trend-kpi-label">{shortPeriodPrefix} Daily Average</span>
          <strong className="trend-kpi-value">{money(metrics.dailyAvg)}</strong>
          <span className="trend-kpi-sub">Benchmark per operating day</span>
        </div>

        <div className="trend-kpi-card">
          <span className="trend-kpi-label">Highest Day ({badgePeriodSuffix})</span>
          <strong className="trend-kpi-value">
            {metrics.peakDay && metrics.peakDay.gross > 0
              ? money(metrics.peakDay.gross)
              : money(0)}
          </strong>
          <span className="trend-kpi-sub">
            {metrics.peakDay && metrics.peakDay.gross > 0
              ? `${metrics.peakDay.weekday}, ${metrics.peakDay.monthDay} (${metrics.peakDay.count} orders)`
              : "No sales recorded in window"}
          </span>
        </div>
      </div>

      <div className="sales-chart-frame">
        <svg
          className="sales-chart-svg"
          viewBox={`0 0 ${chartW} ${chartH}`}
          role="img"
          aria-label={`Bar and line chart comparing daily gross sales over ${rangeConfig.label}`}
        >
          {/* Horizontal grid lines */}
          {[0, 0.5, 1].map((step) => {
            const y = Math.round(padTop + plotH * (1 - step));
            const labelVal = metrics.maxGross * step;
            return (
              <g key={step}>
                <line
                  x1={padLeft}
                  y1={y}
                  x2={chartW - padRight}
                  y2={y}
                  className={step === 0 ? "chart-axis-baseline" : "chart-grid-line"}
                />
                <text
                  x={padLeft - 10}
                  y={y + 4}
                  textAnchor="end"
                  className="chart-axis-label"
                >
                  {compactPeso(labelVal)}
                </text>
              </g>
            );
          })}

          {/* Period average reference line */}
          {metrics.dailyAvg > 0 && metrics.maxGross > 0 && (
            <g>
              {(() => {
                const avgY = Math.round(
                  padTop + plotH * (1 - Math.min(1, metrics.dailyAvg / metrics.maxGross)),
                );
                return (
                  <line
                    x1={padLeft}
                    y1={avgY}
                    x2={chartW - padRight}
                    y2={avgY}
                    className="chart-avg-line"
                  />
                );
              })()}
            </g>
          )}

          {/* Bars and interactive columns */}
          {points.map(({ cx, cy, barH, d, idx }) => {
            const isSelected = idx === safeSelectedIndex;
            const isPeak =
              metrics.peakDay &&
              d.date === metrics.peakDay.date &&
              d.gross > 0 &&
              !d.isToday;
            const barClass = [
              "chart-bar",
              d.isToday ? "is-today" : "",
              isPeak ? "is-peak" : "",
              d.gross === 0 ? "is-zero" : "",
              isSelected ? "is-selected" : "",
            ]
              .filter(Boolean)
              .join(" ");

            const isLast = idx === numCols - 1;
            const showXAxisLabel =
              labelStep === 1 ||
              isLast ||
              (idx % labelStep === 0 && numCols - 1 - idx >= Math.ceil(labelStep * 0.6));
            const showValueAboveBar =
              numCols <= 10
                ? true
                : isSelected || d.isToday || isPeak || (d.gross > 0 && showXAxisLabel);

            return (
              <g
                key={d.date}
                className={`chart-col-group${isSelected ? " selected" : ""}`}
                onClick={() => setSelectedIndex(idx)}
                style={{ cursor: "pointer" }}
              >
                {/* Invisible hit target for easy clicking */}
                <rect
                  x={cx - colW / 2 + 1}
                  y={padTop - 12}
                  width={Math.max(4, colW - 2)}
                  height={plotH + padBottom}
                  rx="6"
                  className="chart-col-hit"
                />

                {/* Background track */}
                <rect
                  x={cx - barW / 2}
                  y={padTop}
                  width={barW}
                  height={plotH}
                  rx={barRadius}
                  className="chart-bar-track"
                />

                {/* Actual value bar */}
                <rect
                  x={cx - barW / 2}
                  y={cy}
                  width={barW}
                  height={barH}
                  rx={barRadius}
                  className={barClass}
                />

                {/* Value label above bar */}
                {showValueAboveBar && (
                  <text
                    x={cx}
                    y={Math.max(15, cy - 8)}
                    textAnchor="middle"
                    className={`chart-value-label${isSelected || d.isToday ? " strong" : ""}`}
                  >
                    {d.gross > 0 ? compactPeso(d.gross) : numCols <= 10 ? "—" : ""}
                  </text>
                )}

                {/* X-axis day label */}
                {showXAxisLabel && (
                  <>
                    <text
                      x={cx}
                      y={padTop + plotH + 18}
                      textAnchor="middle"
                      className={`chart-day-name${d.isToday ? " is-today" : ""}`}
                    >
                      {d.isToday ? "Today" : labelStep === 1 ? d.weekday : d.monthDay}
                    </text>
                    <text
                      x={cx}
                      y={padTop + plotH + 33}
                      textAnchor="middle"
                      className="chart-day-date"
                    >
                      {labelStep === 1 ? d.monthDay : d.weekday}
                    </text>
                  </>
                )}
              </g>
            );
          })}

          {/* Connecting trend line */}
          <polyline
            fill="none"
            points={polylinePoints}
            className="chart-trend-line"
          />

          {/* Trend vertices */}
          {points.map(({ cx, cy, d, idx }) => {
            const isSelected = idx === safeSelectedIndex;
            const dotRadius = isSelected ? 5 : numCols <= 14 ? 3.5 : 2.5;
            return (
              <circle
                key={`pt-${d.date}`}
                cx={cx}
                cy={cy}
                r={dotRadius}
                className={`chart-trend-dot${d.isToday ? " is-today" : ""}${isSelected ? " is-selected" : ""}`}
                onClick={() => setSelectedIndex(idx)}
              />
            );
          })}
        </svg>
      </div>

      {selectedDay && (
        <div className="sales-day-inspector" aria-live="polite">
          <div className="inspector-primary">
            <span className="inspector-badge">
              {selectedDay.isToday ? "TODAY" : selectedDay.weekday.toUpperCase()}
            </span>
            <strong>{formatDayFull(selectedDay.date)}</strong>
          </div>
          <div className="inspector-metrics">
            <span>
              Orders: <strong>{selectedDay.count}</strong>
            </span>
            <span>
              Gross: <strong>{money(selectedDay.gross)}</strong>
            </span>
            <span>
              Net VATable: <strong>{money(selectedDay.net)}</strong>
            </span>
            <span>
              VAT (12%): <strong>{money(selectedDay.vat)}</strong>
            </span>
            <span>
              vs {badgePeriodSuffix} Avg:{" "}
              <strong
                className={
                  vsAvgDiff > 0
                    ? "tone-positive"
                    : vsAvgDiff < 0
                      ? "tone-negative"
                      : ""
                }
              >
                {vsAvgDiff > 0 ? "+" : ""}
                {money(vsAvgDiff)}
              </strong>
            </span>
          </div>
        </div>
      )}
    </section>
  );
}
