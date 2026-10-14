# Oppfølgeren

En liten arbeidsapp for møteoppfølging og daglig prioritering.

**Åpne appen:** https://claude.ai/artifact/5B8rJST7nUem4fjWkJ3Bp1 (privat, bare du har tilgang)

## Hva den gjør

- **Møte:** Lim inn stikkord fra et møte. Claude lager referat, beslutninger og aksjonspunkter med ansvarlig og frist. Du retter og velger hvilke aksjonspunkter som blir oppgaver. Referatet kan kopieres til e-post eller sak.
- **Oppgaver:** Én liste med frister, prioritet og ansvarlig. Filtre for mine, venter på andre, forfalt og ferdig.
- **I dag:** Forfalte oppgaver, frister i dag og resten av uka, og det du venter på fra andre. Claude kan foreslå dagens tre viktigste og skrive en ukeoppsummering til leder.

## Teknisk

- Hele appen er én fil: [`oppfolgeren/index.html`](oppfolgeren/index.html).
- KI-hjelp går via artefaktens innebygde Claude-tilgang (`sample`), så du trenger ingen API-nøkkel.
- Data lagres privat per bruker på claude.ai (`db`, under `data/users/<din id>`). Åpnet utenfor claude.ai lagres alt i nettleseren.

## Personvern

Ikke lim inn fødselsnummer, helseopplysninger eller annen taushetsbelagt informasjon. Tekst du sender til KI-hjelpen behandles av Claude.
