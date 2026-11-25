#!/usr/bin/env python3
"""
Strukturált adatok (schema.org JSON-LD) a statikus HTML-oldalakba.

A közös rész (weboldal, személy, vállalkozás) a public/_app/schema_alap.json-ban van –
ugyanezt használja a PHP-sablon is (blog). Oldalanként ide jönnek a saját elemek
(könyvek, szolgáltatások, videók, GYIK).

Futtatás a repó gyökeréből:  python3 tools/seo_schema.py
A blokk a <!-- schema:start --> … <!-- schema:end --> jelek közé kerül (a </head> elé);
újrafuttatva csak ezt cseréli.
"""
import html
import json
import re
from pathlib import Path

GYOKER = Path(__file__).resolve().parent.parent / "public"
URL = "https://farostudio.hu/"
SZEMELY = {"@id": URL + "#szemely"}
FARO = {"@id": URL + "#faro"}

ALAP = json.loads((GYOKER / "_app" / "schema_alap.json").read_text(encoding="utf-8"))
TERMEKEK = json.loads((GYOKER / "assets" / "data" / "termekek.json").read_text(encoding="utf-8"))["termekek"]


def szoveg(s: str) -> str:
    return re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]+>", " ", s))).strip()


def leiras(f: str) -> str:
    m = re.search(r'<meta name="description" content="([^"]*)"', (GYOKER / f).read_text(encoding="utf-8"))
    return html.unescape(m.group(1)) if m else ""


def ajanlat(ar, url, egyseg=None, elerheto=True):
    o = {"@type": "Offer", "price": ar, "priceCurrency": "HUF", "url": url,
         "availability": "https://schema.org/InStock" if elerheto else "https://schema.org/OutOfStock",
         "seller": FARO}
    if egyseg:
        o["priceSpecification"] = {"@type": "UnitPriceSpecification", "price": ar, "priceCurrency": "HUF", "unitText": egyseg}
    return o


def oldal(f: str, tipus: str = "WebPage", **extra):
    nev = szoveg(re.search(r"<title>(.*?)</title>", (GYOKER / f).read_text(encoding="utf-8"), re.S).group(1))
    o = {"@type": tipus, "@id": URL + ("" if f == "index.html" else f) + "#oldal",
         "url": URL + ("" if f == "index.html" else f), "name": nev, "description": leiras(f),
         "inLanguage": "hu", "isPartOf": {"@id": URL + "#website"}}
    o.update(extra)
    return o


def konyvek():
    lista = []
    for t in TERMEKEK:
        if t["tipus"] != "konyv":
            continue
        k = {"@type": "Book", "@id": URL + "konyvek.html#" + t["id"], "name": t["cim"],
             "author": SZEMELY, "inLanguage": "hu", "url": URL + "konyvek.html#" + t["id"],
             "image": URL + t["borito"]}
        if t.get("alcim"):
            k["alternativeHeadline"] = t["alcim"]
        if t.get("ar"):
            k["offers"] = ajanlat(t["ar"], URL + "konyvek.html#" + t["id"], elerheto=t.get("elerheto", True))
        lista.append(k)
    lista.append({"@type": "Book", "@id": URL + "konyvek.html#a-melyseg-magassaga", "name": "A mélység magassága",
                  "author": SZEMELY, "inLanguage": "hu", "url": URL + "konyvek.html#a-melyseg-magassaga",
                  "creativeWorkStatus": "Készülő"})
    return lista


def videok():
    lista = []
    for t in TERMEKEK:
        if t["tipus"] != "video":
            continue
        lista.append({
            "@type": "Course", "@id": URL + "oktato-videok.html#" + t["id"], "name": t["cim"],
            "description": f"One Brain® kineziológia {t['romai']} modul ({t['alcim']}) ismétlő videósorozata "
                           f"{t.get('videokLeiras', '')}, 90 napos online hozzáféréssel. Csak a modult már elvégzetteknek.",
            "provider": FARO, "instructor": SZEMELY, "inLanguage": "hu",
            "url": URL + "oktato-videok.html#" + t["id"],
            "offers": dict(ajanlat(t["ar"], URL + "oktato-videok.html#" + t["id"]), category="Paid"),
            "hasCourseInstance": {"@type": "CourseInstance", "courseMode": "online"},
        })
    return lista


def szolgaltatas(azon, nev, f, ar=None, egyseg=None, tipus=None, csatorna=True):
    o = {"@type": "Service", "@id": URL + f + "#" + azon, "name": nev, "serviceType": tipus or nev,
         "description": leiras(f), "provider": FARO, "url": URL + f,
         "areaServed": [{"@type": "City", "name": "Szigetszentmiklós"}, {"@type": "Country", "name": "Magyarország"}]}
    if csatorna:
        o["availableChannel"] = [
            {"@type": "ServiceChannel", "name": "Személyesen", "serviceLocation": {
                "@type": "Place", "name": "Csengeri Zsuzsa kineziológus – FARO",
                "address": ALAP[2]["address"]}},
            {"@type": "ServiceChannel", "name": "Online"}]
    if ar:
        o["offers"] = ajanlat(ar, URL + f, egyseg)
    return o


def gyik(f):
    s = (GYOKER / f).read_text(encoding="utf-8")
    kerdesek = re.findall(r"<details>\s*<summary>(.*?)</summary>\s*(.*?)</details>", s, re.S)
    if not kerdesek:
        return None
    return {"@type": "FAQPage", "@id": URL + f + "#gyik", "inLanguage": "hu",
            "mainEntity": [{"@type": "Question", "name": szoveg(k),
                            "acceptedAnswer": {"@type": "Answer", "text": szoveg(v)}} for k, v in kerdesek]}


def program():
    t = next(t for t in TERMEKEK if t["id"] == "szemelyiseg-elemzo")
    f = "szemelyiseg-elemzo-modszer.html"
    return {"@type": "Service", "@id": URL + f + "#program", "name": t["cim"],
            "serviceType": "Személyiség-elemző program segítőknek", "description": leiras(f),
            "provider": FARO, "url": URL + f, "audience": {"@type": "Audience", "audienceType": "kineziológusok, terapeuták, segítők"},
            "offers": ajanlat(t["ar"], URL + f, "1 éves hozzáférés")}


OLDALAK = {
    "index.html": lambda: [oldal("index.html", about=FARO, mainEntity=SZEMELY)],
    "rolam.html": lambda: [oldal("rolam.html", "ProfilePage", mainEntity=SZEMELY)],
    "konyvek.html": lambda: [oldal("konyvek.html", "CollectionPage", about=SZEMELY)] + konyvek(),
    "oktato-videok.html": lambda: [oldal("oktato-videok.html", "CollectionPage")] + videok(),
    "mentoralas.html": lambda: [oldal("mentoralas.html"),
                                szolgaltatas("mentoralas", "Szakmai mentorálás – One Brain kineziológia", "mentoralas.html",
                                             20000, "óra", "Szakmai mentorálás kineziológusoknak")],
    "informalis-kineziologia.html": lambda: [oldal("informalis-kineziologia.html"),
                                             szolgaltatas("informalis-kineziologia", "Informális Kineziológia – személyiségelemzés és oldás",
                                                          "informalis-kineziologia.html", 20000, "első alkalom (kb. 1 óra)", "Kineziológia")],
    "informalis-kineziologia-bejelentkezes.html": lambda: [oldal("informalis-kineziologia-bejelentkezes.html")],
    "szemelyiseg-elemzo-modszer.html": lambda: [oldal("szemelyiseg-elemzo-modszer.html"), program()],
    "bio-narrativa.html": lambda: [oldal("bio-narrativa.html"),
                                   szolgaltatas("bio-narrativa", "Bio-Narratíva program", "bio-narrativa.html",
                                                500000, "10 alkalmas folyamat", "Önismereti program (kineziológia és narratív szemlélet)")]
                                  + [x for x in [gyik("bio-narrativa.html")] if x],
    "bio-narrativa-jelentkezes.html": lambda: [oldal("bio-narrativa-jelentkezes.html")],
    "eloadasok.html": lambda: [oldal("eloadasok.html"),
                               szolgaltatas("eloadasok", "Előadások, közösségi találkozók", "eloadasok.html",
                                            tipus="Előadás, író-olvasó találkozó", csatorna=False)],
    "eloadas-igenyles.html": lambda: [oldal("eloadas-igenyles.html")],
    "mentoralas-jelentkezes.html": lambda: [oldal("mentoralas-jelentkezes.html")],
    "kapcsolat.html": lambda: [oldal("kapcsolat.html", "ContactPage", about=FARO)],
    "impresszum.html": lambda: [oldal("impresszum.html", about=FARO)],
    "aszf.html": lambda: [oldal("aszf.html")],
    "adatkezeles.html": lambda: [oldal("adatkezeles.html")],
    "elallas.html": lambda: [oldal("elallas.html")],
}

JEL = re.compile(r"\n?  <!-- schema:start -->.*?<!-- schema:end -->", re.S)


def main():
    for f, elemek in OLDALAK.items():
        p = GYOKER / f
        s = p.read_text(encoding="utf-8")
        adat = {"@context": "https://schema.org", "@graph": ALAP + elemek()}
        blokk = ("\n  <!-- schema:start -->\n  <script type=\"application/ld+json\">\n"
                 + json.dumps(adat, ensure_ascii=False, separators=(",", ":")).replace("</", "<\\/")
                 + "\n  </script>\n  <!-- schema:end -->")
        s = JEL.sub("", s)
        s = s.replace("\n</head>", blokk + "\n</head>", 1)
        p.write_text(s, encoding="utf-8")
        print("ok", f, len(adat["@graph"]), "elem")


if __name__ == "__main__":
    main()
