// Count Unicode code points, not UTF-16 units or UTF-8 bytes. Never normalize.
export function staffPasswordError(password, confirmation) {
  if (typeof password !== "string") return "Use at least 12 characters.";
  if (password.includes("\0")) return "Passwords cannot contain NUL characters.";
  if (new TextEncoder().encode(password).length > 72)
    return "This password is too long. Use fewer characters; accented letters and emoji may take more space.";
  const characters = Array.from(password);
  // TextEncoder replaces unpaired UTF-16 surrogates; reject instead of altering them.
  if (characters.some((character) => {
    const point = character.codePointAt(0);
    return point >= 0xd800 && point <= 0xdfff;
  })) return "Enter a password using valid Unicode characters.";
  if (characters.length < 12) return "Use at least 12 characters.";
  if (password !== confirmation) return "Passwords do not match.";
  return null;
}
