"""Contrastive sentence embeddings as the self-hosted semantic text layer.

The third attempt, and it borrows the one idea that worked on images.

What failed before, and why:
  - `unitary/multilingual-toxic-xlm-roberta`: one "toxic" label. An
    English threat scored 0.0085 and phishing 0.0014 while a life-drawing
    announcement scored 0.3722. No threshold separates those.
  - Zero-shot NLI (mDeBERTa-XNLI): fired the threat hypothesis on almost
    everything — "lost wallet" 0.9969, a suicide-prevention seminar
    0.9994 — and took 368 seconds per text on this CPU.

What worked on images: CLIP scores by *competition*. A Rodin bronze only
reads as nudity if it beats "a bronze sculpture of a nude human figure".
Adding that competition dropped the safe-set maximum from 0.9152 to
0.3749 and opened a clean gap.

The same shape applied to text: each policy category gets several
exemplar sentences, and they compete against exemplars of ordinary campus
writing — including the awkward cases this university actually produces
(life drawing, war photography, suicide-prevention outreach, an addiction
thesis). A post is scored by cosine similarity to the nearest exemplar on
each side; a category only fires if it beats the benign side by a margin.

Speed matters as much as accuracy here: this runs on every post.
"""

from __future__ import annotations

import argparse
import json
import time
from pathlib import Path

# Several exemplars per category, deliberately varied in phrasing and
# language. One exemplar per category would just be a slower phrase list.
CATEGORY_EXEMPLARS: dict[str, list[str]] = {
    "THR": [
        "Seni bulacağım ve canını yakacağım.",
        "Yarın okula silahla geleceğim, hepiniz göreceksiniz.",
        "I know where you live and you will regret it tomorrow.",
        "Я знаю, где ты живёшь, тебе будет больно.",
    ],
    "HAR": [
        "Bu kız her gün rezil oluyor, sınıfta herkes ona gülsün.",
        "Hepimiz onun hesabına yazalım, silene kadar rahat bırakmayalım.",
        "Everyone screenshot her post and spam it until she deletes her account.",
        "Она уродина, давайте все её затравим.",
    ],
    "SEX": [
        "Çok seksisin, seninle neler yapardım bir bilsen.",
        "Send me nudes and I will help you pass the exam.",
        "Vücudun hakkında konuşmak istiyorum, özelden yaz.",
        "Пришли голые фото, никто не узнает.",
    ],
    "SELF": [
        "Artık dayanamıyorum, bu gece her şeyi bitireceğim.",
        "Kendimi öldürmek istiyorum, kimse aramasın.",
        "Tonight I am going to end my life, please do not look for me.",
        "Я больше не хочу жить, это моё последнее сообщение.",
    ],
    "SCAM": [
        "Kart bilgilerini şu linke gir, burs son gün!",
        "Hesabın kapatılacak, şifreni buradan doğrula.",
        "URGENT: verify your password at this link or your account closes.",
        "Введите данные карты по ссылке, чтобы получить стипендию.",
    ],
    "HATE": [
        "Bu yabancılar pislik, defolup ülkelerine gitsinler.",
        "Onların burada yeri yok, hepsi aşağılık.",
        "These foreigners are vermin and should be thrown out.",
        "Эти приезжие — грязь, им здесь не место.",
    ],
    "VIO": [
        "Onu yakalayıp kafasını duvara vuracağım, kanı aksın.",
        "Dişlerini dökene kadar döveceğim.",
        "I will smash his head until he bleeds.",
    ],
}

# The competition. Ordinary campus writing, weighted towards the subjects
# that trip naive classifiers at an art and design university.
BENIGN_EXEMPLARS: list[str] = [
    "Yarın kütüphanede birlikte ders çalışalım mı?",
    "Cüzdanımı kantinde kaybettim, bulan olursa yazsın.",
    "Fotoğrafçılık kulübü yeni üye alıyor, cuma toplantı var.",
    "Bu sınav çok zordu, moralim bozuldu.",
    "Ring saatleri değişti mi, sabah servisini kaçırdım?",
    "Bu dönem nü etüt dersi var, modelle çalışacağız, anatomi için faydalı.",
    "Rodin'in Öpücük heykeli üzerine sunum hazırlıyorum, çıplak figür analizi var.",
    "My thesis covers the nude in twentieth century photography and gallery censorship.",
    "Sinema dersinde şiddet sahnelerinin kurgusunu analiz ediyoruz, korku türü üzerine.",
    "Savaş fotoğrafçılığı sergisi açıldı, silah ve çatışma görüntüleri tartışılacak.",
    "For our game design project the player fights monsters with a sword.",
    "Psikolojik destek birimi yarın stres ve intihar önleme semineri düzenliyor.",
    "If anyone is struggling this exam season, counselling is free and confidential.",
    "Tez konum uyuşturucu bağımlılığı ve gençler üzerindeki etkisi.",
    "The lecture covered how opioids affect the nervous system.",
    "Münazara kulübü göçmen politikaları üzerine tartışma düzenliyor.",
    "Кто-нибудь знает, во сколько завтра открывается библиотека?",
    "Does anyone have last year's notes for the typography module?",
    "Yönetimin bu kararını hiç doğru bulmuyorum, öğrencilere danışılmadı.",
    "Kafeteryadaki yemekler bu dönem berbat ve fiyatlar yine arttı.",
]


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--model", default="sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2")
    ap.add_argument("--manifest", default="text_benchmark.json")
    args = ap.parse_args()

    import torch
    from transformers import AutoModel, AutoTokenizer

    print(f"loading {args.model} ...", flush=True)
    try:
        tok = AutoTokenizer.from_pretrained(args.model)
        model = AutoModel.from_pretrained(args.model)
    except Exception as exc:  # noqa: BLE001
        print(f"LOAD FAILED: {type(exc).__name__}: {exc}")
        return 1
    model.eval()

    def embed(texts: list[str]) -> "torch.Tensor":
        batch = tok(texts, padding=True, truncation=True, max_length=256, return_tensors="pt")
        with torch.no_grad():
            out = model(**batch).last_hidden_state
        mask = batch["attention_mask"].unsqueeze(-1).float()
        pooled = (out * mask).sum(1) / mask.sum(1).clamp(min=1e-9)
        return pooled / pooled.norm(dim=-1, keepdim=True)

    cats = list(CATEGORY_EXEMPLARS)
    cat_vecs = {c: embed(CATEGORY_EXEMPLARS[c]) for c in cats}
    benign_vecs = embed(BENIGN_EXEMPLARS)

    data = json.loads(Path(args.manifest).read_text(encoding="utf-8"))

    def margins(text: str) -> dict[str, float]:
        v = embed([text])
        benign_best = float((v @ benign_vecs.t()).max())
        out = {}
        for c in cats:
            cat_best = float((v @ cat_vecs[c].t()).max())
            # Margin, not raw similarity. "How much more does this look
            # like a threat than like ordinary campus writing" is the
            # question; raw similarity answers a different one and is why
            # a single-label toxicity score was useless.
            out[c] = cat_best - benign_best
        return out

    started = time.perf_counter()

    print("\nHARMFUL — expected category should have the top margin")
    print(f"{'id':<22}{'exp':<6}{'exp margin':>11}{'best':>8}{'top':>8}")
    print("-" * 60)
    harmful_best = []
    for row in data["harmful"]:
        m = margins(row["text"])
        top = max(m, key=m.get)
        harmful_best.append(m[top])
        print(f"{row['id']:<22}{row['cat']:<6}{m.get(row['cat'], 0):>11.4f}"
              f"{m[top]:>8.4f}{top:>8}")

    print("\nSAFE — every margin should be low")
    print(f"{'id':<22}{'best margin':>12}{'top':>8}")
    print("-" * 46)
    safe_best = []
    for row in data["safe"]:
        m = margins(row["text"])
        top = max(m, key=m.get)
        safe_best.append(m[top])
        mark = "   <-- FP RISK" if m[top] >= 0.10 else ""
        print(f"{row['id']:<22}{m[top]:>12.4f}{top:>8}{mark}")

    elapsed = time.perf_counter() - started
    n = len(data["harmful"]) + len(data["safe"])

    print("\n--- separation ---")
    print(f"harmful best margin: min {min(harmful_best):.4f}  median {sorted(harmful_best)[len(harmful_best)//2]:.4f}")
    print(f"safe    best margin: max {max(safe_best):.4f}  median {sorted(safe_best)[len(safe_best)//2]:.4f}")
    gap = min(harmful_best) - max(safe_best)
    print(f"\ngap = {gap:+.4f}  {'USABLE' if gap > 0 else 'NO SEPARATION'}")
    print(f"{n} texts in {elapsed:.1f}s  ({elapsed/n*1000:.0f} ms/text on CPU)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
