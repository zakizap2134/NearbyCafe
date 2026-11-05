import { useEffect, useMemo, useState } from "react";
import { api } from "../api";
import { money } from "./workflow";

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
  return `₱${ num % 1 === 0 ? num.toLocaleString("en-PH") : num.toFixed(0) }`;
}

export default function SalesTrendChart({ sales, token, onOpenReports }) {
  const anchorDate = isIsoDate(sales?.date) ? sales.date : getManilaToday();
  const [remoteDaily, setRemoteDaily] = useState(null);
  const [selectedIndex, setSelectedIndex] = useState(6);

  useEffect(() => {
    setSelectedIndex(6);
  }, [anchorDate]);

  useEffect(() => {
    if (Array.isArray(sales?.weekly) && sales.weekly.length > 0) {
      setRemoteDaily(null);
      return;
    }
    if (!token || !isIsoDate(sales?.date)) return;
    let cancelled = false;
    const fromDate = offsetIsoDate(anchorDate, -6);
    api("management/reports", {
      token,
      query: { from: fromDate, to: anchorDate, page: 1 },
    })
      .then((res) => {
        if (!cancelled && Array.isArray(res?.daily)) {
          setRemoteDaily(res.daily);
        }
      })
      .catch(() => {
        /* Fallback to today's inline totals if reports endpoint is unreachable */
      });
    return () => {
      cancelled = true;
    };
  }, [anchorDate, sales?.date, sales?.weekly, token]);

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
    } else if (Array.isArray(remoteDaily)) {
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

    const list = [];
    for (let i = 6; i >= 0; i--) {
      const dStr = offsetIsoDate(anchorDate, -i);
      const existing = map.get(dStr);
      const { weekday, monthDay } = formatDayShort(dStr);
      list.push({
        date: dStr,
        weekday,
        monthDay,
        isToday: i === 0,
        count: existing ? existing.count : 0,
        gross: existing ? existing.gross : 0,
        net: existing ? existing.net : 0,
        vat: existing ? existing.vat : 0,
      });
    }
    return list;
  }, [anchorDate, remoteDaily, sales]);

  const metrics = useMemo(() => {
    const totalGross = days.reduce((acc, d) => acc + d.gross, 0);
    const totalOrders = days.reduce((acc, d) => acc + d.count, 0);
    const dailyAvg = totalGross / 7;
    const todayEntry = days[6] || { gross: 0, count: 0 };
    const yesterdayEntry = days[5] || { gross: 0, count: 0 };
    const diffTodayYesterday = todayEntry.gross - yesterdayEntry.gross;

    let diffPctLabel = "No change vs yesterday";
    let diffTone = "neutral";
    if (diffTodayYesterday > 0) {
      diffTone = "positive";
      if (yesterdayEntry.gross > 0) {
        const pct = ((diffTodayYesterday / yesterdayEntry.gross) * 100).toFixed(1);
        diffPctLabel = `+${pct}% vs yesterday`;
      } else {
        diffPctLabel = "Up from ₱0 yesterday";
      }
    } else if (diffTodayYesterday < 0) {
      diffTone = "negative";
      if (yesterdayEntry.gross > 0) {
        const pct = ((diffTodayYesterday / yesterdayEntry.gross) * 100).toFixed(1);
        diffPctLabel = `${pct}% vs yesterday`;
      } else {
        diffPctLabel = "Down vs yesterday";
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
      totalGross,
      totalOrders,
      dailyAvg,
      todayEntry,
      yesterdayEntry,
      diffTodayYesterday,
      diffPctLabel,
      diffTone,
      peakDay,
      maxGross,
    };
  }, [days]);

  const selectedDay = days[selectedIndex] || days[6];
  const vsAvgDiff = selectedDay ? selectedDay.gross - metrics.dailyAvg : 0;

  // SVG coordinate constants
  const chartW = 720;
  const chartH = 220;
  const padLeft = 58;
  const padRight = 22;
  const padTop = 28;
  const padBottom = 46;
  const plotW = chartW - padLeft - padRight;
  const plotH = chartH - padTop - padBottom;
  const colW = plotW / 7;
  const barW = Math.min(44, colW * 0.54);

  const points = days.map((d, idx) => {
    const cx = padLeft + idx * colW + colW / 2;
    const ratio = metrics.maxGross > 0 ? d.gross / metrics.maxGross : 0;
    const barH = Math.max(d.gross > 0 ? 6 : 2, Math.round(ratio * plotH));
    const cy = padTop + plotH - barH;
    return { cx, cy, barH, ratio, d, idx };
  });

  const polylinePoints = points.map((p) => `${p.cx},${p.cy}`).join(" ");

  return (
    <section className="panel sales-trend-panel" aria-label="7-day sales visual comparison">
      <div className="sales-trend-header">
        <div>
          <p className="eyebrow">REVENUE TRAJECTORY · LAST 7 DAYS</p>
          <h2>Weekly Sales Comparison</h2>
          <p className="subtle">
            Daily gross revenue and order volume from {days[0]?.monthDay || days[0]?.date} to{" "}
            {days[6]?.monthDay || days[6]?.date}. Select any bar to inspect day-over-day differences.
          </p>
        </div>
        {onOpenReports && (
          <button type="button" className="compact-btn" onClick={onOpenReports}>
            Full period reports →
          </button>
        )}
      </div>

      <div className="sales-trend-kpis">
        <div className="trend-kpi-card">
          <span className="trend-kpi-label">7-Day Gross Total</span>
          <strong className="trend-kpi-value">{money(metrics.totalGross)}</strong>
          <span className="trend-kpi-sub">{metrics.totalOrders} completed orders</span>
        </div>

        <div className="trend-kpi-card">
          <span className="trend-kpi-label">Today vs Yesterday</span>
          <strong className={`trend-kpi-value tone-${metrics.diffTone}`}>
            {metrics.diffTodayYesterday > 0 ? "+" : ""}
            {money(metrics.diffTodayYesterday)}
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
          <span className="trend-kpi-label">7-Day Daily Average</span>
          <strong className="trend-kpi-value">{money(metrics.dailyAvg)}</strong>
          <span className="trend-kpi-sub">Benchmark per operating day</span>
        </div>

        <div className="trend-kpi-card">
          <span className="trend-kpi-label">Highest Day (7d)</span>
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
          aria-label="Bar and line chart comparing daily gross sales over the last 7 days"
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

          {/* 7-day average reference line */}
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
            const isSelected = idx === selectedIndex;
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

            return (
              <g
                key={d.date}
                className={`chart-col-group${isSelected ? " selected" : ""}`}
                onClick={() => setSelectedIndex(idx)}
                style={{ cursor: "pointer" }}
              >
                {/* Invisible hit target for easy clicking */}
                <rect
                  x={cx - colW / 2 + 4}
                  y={padTop - 12}
                  width={colW - 8}
                  height={plotH + padBottom}
                  rx="8"
                  className="chart-col-hit"
                />

                {/* Background track */}
                <rect
                  x={cx - barW / 2}
                  y={padTop}
                  width={barW}
                  height={plotH}
                  rx="6"
                  className="chart-bar-track"
                />

                {/* Actual value bar */}
                <rect
                  x={cx - barW / 2}
                  y={cy}
                  width={barW}
                  height={barH}
                  rx="5"
                  className={barClass}
                />

                {/* Value label above bar */}
                <text
                  x={cx}
                  y={Math.max(15, cy - 8)}
                  textAnchor="middle"
                  className={`chart-value-label${isSelected || d.isToday ? " strong" : ""}`}
                >
                  {d.gross > 0 ? compactPeso(d.gross) : "—"}
                </text>

                {/* X-axis day label */}
                <text
                  x={cx}
                  y={padTop + plotH + 18}
                  textAnchor="middle"
                  className={`chart-day-name${d.isToday ? " is-today" : ""}`}
                >
                  {d.isToday ? "Today" : d.weekday}
                </text>
                <text
                  x={cx}
                  y={padTop + plotH + 33}
                  textAnchor="middle"
                  className="chart-day-date"
                >
                  {d.monthDay}
                </text>
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
          {points.map(({ cx, cy, d, idx }) => (
            <circle
              key={`pt-${d.date}`}
              cx={cx}
              cy={cy}
              r={idx === selectedIndex ? 5 : 3.5}
              className={`chart-trend-dot${d.isToday ? " is-today" : ""}${idx === selectedIndex ? " is-selected" : ""}`}
              onClick={() => setSelectedIndex(idx)}
            />
          ))}
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
              vs 7d Avg:{" "}
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
