# Administratorvejledning

Denne vejledning henvender sig til systemadministratorer, driftsansvarlige og teknikere, der skal installere, konfigurere og vedligeholde OpenCase.

Vejledningen beskriver hele forløbet fra installation af OpenCase til den daglige administration og fejlfinding.

!!! info

    Denne vejledning forudsætter, at læseren har administratoradgang til Nextcloud og kendskab til serverdrift og systemadministration.

---

## Vejledningens indhold

Administratorvejledningen er opdelt i en række afsnit, som følger den naturlige proces fra installation til drift.

| Emne | Beskrivelse |
|------|-------------|
| [Installation – Basic-version](installation-basic-version/) | Installation og grundkonfiguration af OpenCase Basic. |
| [Installation – Enterprise-version](installation-enterprise-version/) | Installation af Enterprise-funktioner og integrationer. |
| [Administrer skabeloner](administrer-skabeloner/) | Oprettelse og vedligeholdelse af dokumentskabeloner. |
| [Konfiguration](konfiguration/) | Konfiguration af systemindstillinger, værdilister og standardværdier. |
| [Lokale Nextcloud-brugere](lokale-brugere.md) | Rettigheder lor Lokale Nextcloud-brugere. |
| [Transaktionslog](transaktionslog.md) | Søgning efter brugerhandlinger i en transaktionslog. |
| [Eksport af sager](eksport-af-sager.md) | konfiguration af automatisk eksportere afsluttede sager. |
| [Fejlfinding](fejlfinding/) | Fejlsøgning og løsning af de mest almindelige problemer. |
| [Logfiler](logfiler/) | Oversigt over OpenCases logfiler og logniveauer. |
| [OCC-kommandoer](occ-kommandoer/) | Reference over OpenCases kommandolinjeværktøjer. |

---

## Installation

Før OpenCase kan tages i brug, skal Nextcloud og de nødvendige komponenter installeres og konfigureres.

Administratorvejledningen beskriver blandt andet:

- systemkrav
- installation af OpenCase
- integration med Serviceplatformen
- adgangsstyring
- klassifikation
- API-konfiguration

Organisationer med Enterprise-licens installerer efterfølgende Enterprise-komponenterne.

---

## Administration

Når installationen er gennemført, kan OpenCase tilpasses organisationens behov.

Som administrator kan du blandt andet:

- konfigurere systemindstillinger
- administrere værdilister
- oprette dokumentskabeloner
- konfigurere standardværdier
- administrere integrationer

Disse indstillinger påvirker alle brugere af OpenCase.

---

## Drift

Den daglige drift omfatter blandt andet:

- overvågning af logfiler
- synkronisering med eksterne tjenester
- genindeksering af søgning
- vedligeholdelse af certifikater
- overvågning af baggrundsjob

OpenCase leverer en række OCC-kommandoer, som understøtter drift og vedligeholdelse.

---

## Fejlfinding

Hvis der opstår problemer, kan administratoren anvende:

- Nextcloud-logfiler
- OpenCase trace-log
- OCC-kommandoer
- integrationsværktøjer

Administratorvejledningen beskriver de mest almindelige fejl og de anbefalede løsninger.

---

## Anbefalet rækkefølge

Hvis du installerer OpenCase første gang, anbefales det at følge vejledningen i denne rækkefølge:

1. Installation – Basic-version
2. Installation – Enterprise-version (hvis relevant)
3. Konfiguration
4. Administrer skabeloner
5. Fejlfinding
6. Logfiler
7. OCC-kommandoer

---

## Målgruppe

Denne vejledning er skrevet til:

- systemadministratorer
- Nextcloud-administratorer
- driftsansvarlige
- teknikere
- konsulenter og implementeringspartnere

Den beskriver de administrative funktioner i OpenCase og forudsætter ikke kendskab til den daglige sagsbehandling.

---

## Se også

- [Brugervejledning](../)
- [Installation – Basic-version](installation-basic-version/)
- [Konfiguration](konfiguration/)