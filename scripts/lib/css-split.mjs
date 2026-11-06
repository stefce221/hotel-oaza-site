/* Split today's sections.css at its section markers, and prove the pieces join back byte for byte. */
import { createHash } from "node:crypto";

/** sha256 of git show ba6ab83:assets/css/sections.css with LF line endings (47,892 characters). */
export const ORIGINAL_SHA256 = "bd45f4b88e0aef5a8a35a7dd9c202e5c695cafadb7e3a723d23f80d138ac744e";

const FILES = new Map([
  ["Shared media", "01-shared-media.css"],
  ["Hero", "02-hero.css"],
  ["Booking bar", "03-booking.css"],
  ["Intro", "04-intro.css"],
  ["Rooms", "05-rooms.css"],
  ["Room photo frame + key tag", "06-room-frame.css"],
  ["Door hanger: practical notes under the tabs", "07-hanger.css"],
  ["Dining (dark)", "08-dining.css"],
  ["Events", "09-events.css"],
  ["Frames", "10-frames.css"],
  ["AquaBella", "11-aquabella.css"],
  ["Location", "12-location.css"],
  ["Postcard frame (Štip photo)", "13-postcard.css"],
  ["Landmarks: a walking trail", "14-landmarks.css"],
  ["Contact", "15-contact.css"],
  ["Enquiry: compact boxed fields", "16-enquiry.css"],
  ["Responsive", "17-responsive.css"],
]);

const DASHED = /^\/\* -{3,} (.+?) -{3,} \*\/$/;

export function fileFor(title) {
  const file = FILES.get(title);
  if (!file) throw new Error(`No file name for section "${title}"`);
  return file;
}

/**
 * Cut at every "/* ---------- Title ---------- *\/" line and at the "Responsive" banner.
 * The file's opening banner stays with the first chunk. Joining the chunks gives back `css` exactly.
 * @returns {{title: string, text: string}[]}
 */
export function splitCss(css) {
  const lines = css.split("\n");
  const starts = [];
  lines.forEach((line, i) => {
    const m = line.match(DASHED);
    if (m) starts.push({ i, title: m[1] });
    else if (i > 0 && line.startsWith("/* ====") && lines[i + 1]?.trim() === "Responsive") starts.push({ i, title: "Responsive" });
  });
  if (!starts.length) throw new Error("No section markers found");
  return starts.map((s, n) => {
    const from = n === 0 ? 0 : s.i;
    const last = n === starts.length - 1;
    const to = last ? lines.length : starts[n + 1].i;
    return { title: s.title, text: lines.slice(from, to).join("\n") + (last ? "" : "\n") };
  });
}

export function sha256(text) {
  return createHash("sha256").update(text.replace(/\r\n/g, "\n")).digest("hex");
}
