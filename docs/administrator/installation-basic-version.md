# Installation – Basic-version

Denne vejledning beskriver installation og grundkonfiguration af **OpenCase Basic**.

Efter installationen vil OpenCase være klar til brug med den fælleskommunale organisationsservice, klassifikation og adgangsstyring.

!!! info

    Installationen forudsætter administratoradgang til Nextcloud-serveren samt adgang til den fælleskommunale Serviceplatform.

---

# Forudsætninger

Inden OpenCase installeres skal følgende komponenter være installeret og konfigureret.

## Nextcloud

OpenCase understøtter:

- Nextcloud **34.x**

Det anbefales, at Nextcloud er opdateret med de seneste sikkerhedsopdateringer.

## Cron jobs

Nextcloud skal være konfigureret til at anvende **Cron** som baggrundsjob.

Cron anvendes blandt andet til:

- synkronisering af organisationsdata
- indeksering af søgning
- afsendelse af notifikationer
- øvrige baggrundsopgaver

Kontrollér under:

> **Systemindstillinger → Grundlæggende indstillinger → Baggrundsjob**

at **Cron** er valgt.

## Elasticsearch

OpenCase anvender Elasticsearch til fuldtekstsøgning.

Installer og konfigurer en understøttet Elasticsearch-instans, inden OpenCase installeres.

## Påkrævede Nextcloud-apps

Følgende apps skal være installeret og aktiveret:

- Teammapper
- Fuldtekstsøgning
- Fuldtekstsøgning – Filer
- Fuldtekstsøgning – Elasticsearch Platform

---

# Installation af OpenCase

Installer OpenCase fra **Nextcloud App Store**.

1. Åbn **Apps**.
2. Søg efter **OpenCase**.
3. Klik **Installer**.
4. Aktiver appen.

Når installationen er gennemført, vises OpenCase i administrationsmenuen.

Åbn:

> **Systemindstillinger → OpenCase**

Øverst vises version og versionsnummer og under Forudsætteninger valideres at påkrævede apps er installeret.

![Version](../images/administration/version.png)


---

# Konfiguration af Organisation

Åbn:

> **Systemindstillinger → OpenCase → Serviceplatformen → Organisation**

![Organisation](../images/administration/organisation.png)

---

## MitID Erhverv

Bestil et **systemcertifikat** til organisationen via MitID Erhverv.

Certifikatet anvendes ved kommunikation med den fælleskommunale Serviceplatform.


---

## Serviceaftale

Opret en serviceaftale i **Fælleskommunal Administrator** for:

**SF1500 – Organisation**

---

## Registrer systemcertifikat

Importer det udstedte systemcertifikat i OpenCase.
Certifikatet anvendes ved alle kald til organisationsservicen.

Kopier certifikatet til en mappe på serveren og kør kommandoen i Nextcloud rod mappen:

```bash
php occ opencase:register-certificate
```

Vælg '0' for Primary og indtast stien til certifikatet samt password.

![Organisation](../images/administration/primary-certifikate.png)

Certifikatet oplysninger kan nu ses i brugergrænsefladen.

![Certifikat oplysninger](../images/administration/view-certificate.png)

---

## Opret Teammapper

Hvis Teammapper skal oprettes automatisk for nye sager, aktiver:

**Opret Teammapper**

![Opret Teammapper](../images/administration/opret-teammapper.png)
---

## Konfigurer endpoints

Vælg enten test- eller produktionsmiljø.

### Test

| Service | Endpoint |
|---------|----------|
| Organisation | `https://organisation.eksterntest-stoettesystemerne.dk/organisation/organisationsystem/6/` |
| Organisationenhed | `https://organisation.eksterntest-stoettesystemerne.dk/organisation/organisationenhed/6/` |
| Organisationsfunktion | `https://organisation.eksterntest-stoettesystemerne.dk/organisation/organisationfunktion/6/` |
| Bruger | `https://organisation.eksterntest-stoettesystemerne.dk/organisation/bruger/6/` |
| Person | `https://organisation.eksterntest-stoettesystemerne.dk/organisation/person/6/` |
| Adresse | `https://organisation.eksterntest-stoettesystemerne.dk/organisation/adresse/6/` |

### Produktion

| Service | Endpoint |
|---------|----------|
| Organisation | `https://organisation.stoettesystemerne.dk/organisation/organisationsystem/6/` |
| Organisationenhed | `https://organisation.stoettesystemerne.dk/organisation/organisationenhed/6/` |
| Organisationsfunktion | `https://organisation.stoettesystemerne.dk/organisation/organisationfunktion/6/` |
| Bruger | `https://organisation.stoettesystemerne.dk/organisation/bruger/6/` |
| Person | `https://organisation.stoettesystemerne.dk/organisation/person/6/` |
| Adresse | `https://organisation.stoettesystemerne.dk/organisation/adresse/6/` |

---

## Initial synkronisering

Når forbindelsen til organisationsservicen er konfigureret:

Kør en **initial synkronisering**.

![Synkroniser organisationer nu](../images/administration/synkroniser-nu.png)

Kontrollér at organisationer importeres korrekt.

---

## Automatisk synkronisering

Aktivér synkroniseringsjobbet.

Det anbefales at synkronisere **én gang i døgnet**.

![Automatisk synkronisering](../images/administration/automatisk-synkronisering.png)

---

# Konfiguration af Klassifikation

Åbn:

> **Systemindstillinger → OpenCase → Serviceplatformen → Klassifikation**

!!! info "Enterprise"
    I Enterprise versionen kan KL emneplan og handlingsfacetter synkroniseres automatisk. I Basic versionen skal CSV filer med emneplan og handlingsfacetter uploades manuelt.

Upload følgende CSV-filer.

![Upload klassifikation](../images/administration/upload-klassifikation.png)


## Emneplan

Format:

```text
uuid;code;title;description;active
```

![Emneplan eksempel](../images/administration/emneplan-sample.png)

[Download eksempel på CSV fil med emneplan fra Korsbæk Kommune](../../administrator/Emneplan.csv){ .md-button .md-button--primary }

## Handlingsfacetter

Format:

```text
uuid;code;title;description;active
```

![Handlingsfacetter eksempel](../images/administration/handlingsfacetter-sample.png)

[Download eksempel på CSV fil med handlingsfacetter fra Korsbæk Kommune](../../administrator/Handlingsfacetter.csv){ .md-button .md-button--primary }


Når filerne er importeret, kan KLE-numre og handlingsfacetter anvendes ved oprettelse af sager.

---

# Konfiguration af Adgangsstyring

!!! warning "Nextcloud med DK Kommunal Adgang app'en"

    Miljøer der tidligere er konfigureret med adgangsstyring med app'en "DK Kommunal Adgang" skal først have denne adgangsstyring deaktiveret.

    ![DK Kommunal Adgangsstyring](../images/administration/dk-kommunal-adgangsstyring.png)

    Brugere der er oprettet med "DK Kommunal Adgang" bliver automatisk oprettet i OpenCase, når de logger ind igen efter adgangsstyringen er aktiveret i OpenCase, hvis de har de nødvendige adgangsrettigheder.


Åbn:

> **Systemindstillinger → OpenCase → Serviceplatformen → Adgangsstyring**

![Adgangsstyring](../images/administration/adgangsstyring.png)

---

## Context Handler

Konfigurer endpoint for metadata til Context Handler.


### Test

```text
https://n2adgangsstyring.eksterntest-stoettesystemerne.dk/runtime/saml2/metadata.idp?samlprofile=nemlogin3
```

### Produktion

```text
https://n2adgangsstyring.stoettesystemerne.dk/runtime/saml2/metadata.idp?samlprofile=nemlogin3
```

---

## SAML Metadata

1. Download SAML Metadata fra OpenCase.
2. Upload metadatafilen i **Fælleskommunal Administrator**.

---

## Opret roller

I Fælleskommunal Administrator:

- Opret brugersystemroller.
- Tildel jobfunktionsroller til brugerne.

### Brugersystemroller

Brugersystemrollerne oprettes med følgende URI'er, hvor `<domæne>` er OpenCase-installationens domæne:

| Rolle | URI | Dataafgrænsninger |
|---|---|---|
| Nextcloud bruger | `http://<domæne>/roles/usersystemrole/nextclouduser/1` | Ingen |
| Bruger | `http://<domæne>/roles/usersystemrole/opencaseuser/1` | Organisation, KLE, Følsomhed |
| Læsebruger | `http://<domæne>/roles/usersystemrole/opencasereaduser/1` | Organisation, KLE, Følsomhed |
| Skabelon administrator | `http://<domæne>/roles/usersystemrole/opencasetemplateadministrator/1` | Ingen |
| OpenCase administrator | `http://<domæne>/roles/usersystemrole/opencaseadministrator/1` | Ingen |
| Systemadministrator | `http://<domæne>/roles/usersystemrole/systemadministrator/1` | Ingen |

Rollerne giver følgende adgang:

#### nextclouduser – Nextcloud bruger

Brugeren oprettes og aktiveres i Nextcloud og kan anvende Nextcloud (filer, Talk m.m.), men får ingen adgang til OpenCase. OpenCase vises ikke i menuen, og brugeren tildeles ingen OpenCase-roller eller organisationer.

#### opencaseuser – Bruger

Giver adgang til OpenCase med læse- og skriveadgang til sager og dokumenter inden for de tildelte dataafgrænsninger (organisation, KLE-numre og følsomhed). Brugeren tilføjes de organisationers grupper, der er angivet i dataafgrænsningen, og får dermed adgang til organisationernes mapper.

#### opencasereaduser – Læsebruger

Som *Bruger*, men med kun læseadgang til sager og dokumenter inden for de tildelte dataafgrænsninger. Har en bruger både *Bruger* og *Læsebruger* på samme afgrænsning, gælder skriveadgangen.

#### opencasetemplateadministrator – Skabelon administrator

Giver adgang til menupunktet **Administrer skabeloner**, hvor dokumentskabeloner kan oprettes, redigeres og slettes. Se [Administrer skabeloner](administrer-skabeloner.md).

#### opencaseadministrator – OpenCase administrator

Giver adgang til menupunktet **Konfiguration** i OpenCase, hvor bl.a. konfigurationsværdier, kodelister, brugerdefinerede felter, adgangsmasker, dokumentindeksregler og adskillelsesark administreres. Rollen giver også adgang til at administrere skabeloner. Se [Konfiguration](konfiguration.md).

#### systemadministrator – Systemadministrator

Brugeren tilføjes Nextclouds `admin`-gruppe og får dermed fuld adgang til Nextclouds administration, herunder **Systemindstillinger → OpenCase**. Rollen giver ikke i sig selv adgang til sager.

!!! note "Kombination af roller"

    *Skabelon administrator* og *OpenCase administrator* giver kun adgang til funktioner i OpenCase-app'en og skal derfor kombineres med *Bruger* eller *Læsebruger*, for at brugeren kan åbne OpenCase. En bruger med *Nextcloud bruger* alene og ingen OpenCase-roller kan logge ind i Nextcloud, men ikke anvende OpenCase.

    Rollerne synkroniseres ved hvert login. Fjernes en rolle fra brugeren i Fælleskommunal Administrator, fjernes den tilsvarende adgang ved næste login. Har brugeren ingen af ovenstående roller, deaktiveres brugeren i Nextcloud.

---

## Aktivér adgangsstyring

Når konfigurationen er færdig, aktiveres:

**Enable adgangsstyring**

Herefter vil OpenCase anvende den fælleskommunale adgangsstyring ved adgang til sager og dokumenter.

---

# Systemindstillinger af API

Åbn:

> **Administration → OpenCase → API**

![API](../images/administration/api-fanen.png)


---

## Request header

Hvis API'et anvendes bag Nginx, anbefales følgende konfiguration:

```nginx
client_header_buffer_size 32k;
large_client_header_buffers 4 32k;
```

---

## Certifikat til adgangsstyring

Importer certifikatet:

Kopier den offentlige nøgle fra KOMBIT adgangsstyringscertifikatet til en mappe på serveren og kør kommandoen i Nextcloud rod mappen:

```bash
php occ opencase:register-api-client
```
Indtast navn og sti til certifikatet. Vælg 2 for Adgangsstyring.

![Certifikat til adgangsstyring](../images/administration/certificate-adgangsstyring.png)


---

## Klientcertifikat

Hvis eksterne systemer skal anvende OpenCase API'et, registreres det relevante klientcertifikat.

Herefter kan klienten autentificere sig mod API'et.

---

# Kontrol

Når installationen er gennemført, anbefales det at kontrollere:

- ✅ OpenCase starter uden fejl.
- ✅ Organisationer er synkroniseret.
- ✅ KLE-emneplan er importeret.
- ✅ Handlingsfacetter er importeret.
- ✅ Adgangsstyring fungerer.
- ✅ Fuldtekstsøgning fungerer.
- ✅ Teammapper oprettes (hvis aktiveret).

Hvis alle punkter er opfyldt, er OpenCase Basic klar til brug.

---

## Næste trin

Efter installationen anbefales det at:

- [Konfigurere indstillinger](./konfiguration.md)
- [Konfigurere dokumentskabeloner](./administrer-skabeloner.md)
