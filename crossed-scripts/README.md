# Crossed Scripts – POC

Spillbar prototype av grunnspillet i konseptdokumentet (F01–F12): rom, hemmelige rollekort,
synkroniserte replikker, skjult gjetting og avstemning, poeng og sluttstilling.

Alle åpner samme nettadresse på hver sin telefon. Ingen installasjon, konto eller betaling.

## Innhold

```
web/                 Lastes opp til webhotellet
  index.html, app.js, style.css, qrcode.js    Klienten
  api.php                                     Eneste inngang til serveren
  private/                                    Kun for serveren (sperret med .htaccess)
    game.php                                  Spillmotor: tilstand, tilgang, poeng
    scenes/*.json                             Scenene med fasit
    config.sample.php                         Mal for databaseoppsett
tests/
  engine_test.php    93 akseptansetester for regler, poeng og informasjonsgrenser
  e2e.js             Full økt med fem simulerte telefoner i nettleser
```

## Installasjon på webhotell (PHP 8.0+ og MySQL/MariaDB)

1. Last opp **innholdet** i `web/` til rotmappen for domenet (ofte `public_html`).
   Få med den skjulte filen `private/.htaccess`.
2. Opprett en tom database og en databasebruker i kontrollpanelet.
3. Kopier `private/config.sample.php` til `private/config.php` og fyll inn databasenavn,
   brukernavn og passord. Tabellene (`cs_games`, `cs_presence`, `cs_attempts`) opprettes
   automatisk første gang noen oppretter et spill.
4. Åpne domenet på telefonen og opprett et spill.

**Kontroller dette etter opplasting:** Åpne
`https://dittdomene.no/private/scenes/intervju-date.json` i nettleseren. Du skal få
«403 Forbidden». Får du se innholdet, ignorerer serveren `.htaccess`, og fasiten er da
åpen for alle. Ikke spill før det er rettet.

Uten `config.php` bruker spillet en SQLite-fil i `private/data/`. Det krever at mappen er
skrivbar for PHP og er greit for lokal testing.

## Teste med færre telefoner

Standard er 4 til 8 deltakere. Sett `'min_players' => 3` i `config.php` for å prøve med tre.
Du kan også åpne flere private nettleservinduer på samme maskin; hvert vindu blir én deltaker.

## Lokal kjøring og tester

```bash
php -S 127.0.0.1:8099 -t web
```

```bash
php tests/engine_test.php
```

```bash
node tests/e2e.js
```

## Valg som er tatt i POC-en

| Beslutning | Valg i POC-en |
| --- | --- |
| B01 Skuespillergjetting | Verten slår av eller på før økten. Standard: på, 1 poeng |
| B02 Null stemmer | 0 poeng til begge |
| B03 Deltakerantall | 4 til 8 |
| B04 Opptredener | To hver etter ringplanen. «Kort økt» gir én hver ved partall |
| B05 Alternativer og frist | Fire alternativer, 30 sekunder, verten kan legge til 30 |
| B06 Språk | Norsk bokmål |
| B07 Plattform | Nettapp som midlertidig testprototype |
| B08 Video | Ikke med |
| B09 Konto | Gjestenavn |
| B10 Betaling | Ikke med |
| B11 Endring i gruppen | Ingen nye deltakere etter start |

## Slik er hemmelighetene beskyttet

- Serveren avgjør rundestatus, tur, frist og poeng. Klienten sender bare handlinger.
- En telefon får aldri mer enn rollen trenger: skuespillere får eget kort og egen replikk når
  det er deres tur, tilskuere får ingenting før svarperioden, og fasit sendes først etter låsing.
- Verten har administrative knapper, men ser ikke mer enn rollen sin.
- Deltakeren identifiseres med en hemmelig nøkkel lagret på telefonen, ikke med navnet.
  Åpner du siden på nytt, kommer du tilbake til samme deltaker og status.

## Nye scener

Legg en ny JSON-fil i `private/scenes/` etter mønster av de eksisterende. Scener som ikke
består valideringen (to roller, fullstendige kort, nøyaktig én fasit blant fire alternativer)
eller mangler `"published": true`, brukes ikke. En påbegynt runde bruker en låst kopi av scenen.

## Kjente begrensninger

- Telefonene spør serveren om status én gang i sekundet. Turbytte kan derfor ta opptil et sekund.
- Spilltilstanden lagres som ett JSON-dokument per rom, ikke i den normaliserte datamodellen
  fra kapittel 12. Poeng føres likevel som unike poenghendelser.
- Scenene er utkast som ikke er blindtestet med publikum.
- Rom slettes 24 timer etter siste aktivitet.
- Ikke testet mot MySQL/MariaDB i utviklingsmiljøet, bare mot SQLite.
