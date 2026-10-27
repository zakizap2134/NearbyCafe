import logo from "../assets/nearby-cafe-logo.png";

// The supplied artwork stays unchanged; CSS frames the wordmark's original canvas.
export default function Brand({ compact = false, header = false }) {
  return (
    <div className={`brand nearby-brand${compact ? " compact" : ""}${header ? " header-logo" : ""}`}>
      <div className="brand-artwork">
        <img src={logo} alt="Nearby Café" />
      </div>
      {!header && <small>
        {compact ? "VENUEVUE WORKSPACE" : "GOOD COFFEE, ALWAYS NEARBY"}
      </small>}
    </div>
  );
}
