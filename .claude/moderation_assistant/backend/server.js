require("dotenv").config();
const express = require("express");
const cors = require("cors");
const OpenAI = require("openai");

const app = express();
app.use(cors());
app.use(express.json({ limit: "1mb" }));

if (!process.env.OPENAI_API_KEY) {
  console.warn("OPENAI_API_KEY tanımlı değil.");
}

const openai = new OpenAI({ apiKey: process.env.OPENAI_API_KEY });

function normalizeText(value) {
  return value.toLowerCase().normalize("NFKD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/[0@]/g, "o").replace(/[1!|]/g, "i")
    .replace(/[3]/g, "e").replace(/[4]/g, "a")
    .replace(/[5$]/g, "s").replace(/[7]/g, "t")
    .replace(/[^a-zа-яё0-9\s]/gi, " ").replace(/\s+/g, " ").trim();
}

const bannedWords = [
  "orospu", "sik", "siktir", "amk", "amına", "yarrak", "piç", "ibne", "kahpe", "salak",
  "fuck", "fucking", "motherfucker", "bitch", "cunt", "dick", "pussy", "whore", "slut", "retard",
  "блядь", "бля", "сука", "хуй", "пизда", "ебать", "еблан", "мразь"
];
const bannedRegex = new RegExp(`(^|\\s)(${bannedWords.join("|")})(?=$|\\s)`, "iu");
const politicalPatterns = [
  /\b(akp|chp|mhp|hdp|dem|iyi parti|cumhurbaşkanı|seçim|hükümet|milletvekili|politikacı|siyaset)\b/iu,
  /\b(president|election|government|politician|politics|republican|democrat|conservative|liberal)\b/iu,
  /\b(президент|выборы|правительство|политика|политик|партия|кремль)\b/iu
];

function localFlags(text) {
  const normalized = normalizeText(text);
  return {
    profanity: bannedRegex.test(normalized),
    politics: politicalPatterns.some((pattern) => pattern.test(normalized))
  };
}

async function moderate(text) {
  const response = await openai.moderations.create({
    model: "omni-moderation-latest",
    input: text
  });
  return response.results[0];
}

function decide(flags, result) {
  const categories = result.categories || {};
  const scores = result.category_scores || {};
  const thresholds = { sexual: 0.08, sexualMinors: 0.01, hate: 0.08, harassment: 0.10, violence: 0.08 };
  const reasons = [];
  if (flags.profanity) reasons.push("profanity");
  if (flags.politics) reasons.push("politics");
  if (categories.sexual || (scores.sexual || 0) >= thresholds.sexual) reasons.push("sexual_content");
  if (categories["sexual/minors"] || (scores["sexual/minors"] || 0) >= thresholds.sexualMinors) reasons.push("sexual_minors");
  if (categories.hate || (scores.hate || 0) >= thresholds.hate) reasons.push("racism_or_hate");
  if (categories.harassment || (scores.harassment || 0) >= thresholds.harassment) reasons.push("harassment");
  if (categories.violence || (scores.violence || 0) >= thresholds.violence) reasons.push("violence");
  return { allowed: reasons.length === 0, reasons, scores };
}

app.post("/api/moderate-text", async (req, res) => {
  try {
    const { text } = req.body;
    if (typeof text !== "string" || !text.trim()) return res.status(400).json({ error: "text alanı zorunludur." });
    if (text.length > 5000) return res.status(400).json({ error: "Metin çok uzun." });
    const result = decide(localFlags(text), await moderate(text));
    res.json({ ...result, languageSupport: ["tr", "en", "ru"] });
  } catch (error) {
    console.error(error);
    res.status(503).json({ allowed: false, reasons: ["moderation_unavailable"], error: "İçerik doğrulanamadı." });
  }
});

app.listen(process.env.PORT || 3000, () => console.log("Moderation API çalışıyor."));
