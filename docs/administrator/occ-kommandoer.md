# OpenCase OCC-kommandoer

OpenCase leverer en række kommandolinjeværktøjer, som kan udføres fra Nextclouds installationsmappe.

Kommandoerne køres med:

```bash
php occ opencase:<kommando> [parametre]
```

---

# Konfiguration

| Kommando | Beskrivelse |
|----------|-------------|
| `opencase:config:get` | Udskriver en enkelt værdi fra tabellen `opencase_config`. |
| `opencase:config:list` | Viser alle konfigurationsværdier i `opencase_config`. |
| `opencase:config:set` | Opretter eller ændrer en konfigurationsværdi. |

---

# Logning

| Kommando | Beskrivelse |
|----------|-------------|
| `opencase:trace-enable` | Aktiverer detaljeret trace-logning. |
| `opencase:trace-disable` | Deaktiverer trace-logning. |

---

# Vedligeholdelse

| Kommando | Beskrivelse |
|----------|-------------|
| `opencase:export-closed-cases` | Eksporterer lukkede sager til den konfigurerede eksportmappe. |
| `opencase:register-api-client` | Registrerer et klientcertifikat til det mTLS-beskyttede API. |
| `opencase:register-certificate` | Registrerer eller opdaterer et PKCS#12-certifikat. |
| `opencase:reindex` | Genopbygger OpenCases fuldtekstindeks i Elasticsearch. |
| `opencase:filecache:sync` | Genopbygger OpenCases virtuelle filsystem i `oc_filecache`. |

---

# Organisation og adgangsstyring

| Kommando | Beskrivelse |
|----------|-------------|
| `opencase:issue-token` | Udsteder et SAML2-token via STS (WS-Trust 1.3). |
| `opencase:generate-saml-metadata` | Genererer SAML-metadata. |
| `opencase:fetch-orgs` | Henter organisationer fra organisationstjenesten. |
| `opencase:get-org` | Henter oplysninger om en organisationsenhed. |
| `opencase:sync-org` | Synkroniserer organisationer. |
| `opencase:get-user` | Henter oplysninger om en bruger. |
| `opencase:update-user` | Opdaterer brugeroplysninger fra Serviceplatformen. |
| `opencase:privileges:recalculate` | Genberegner alle brugeres adgangsrettigheder. |

---

# Enterprise-kommandoer

!!! info "Enterprise"
    Følgende kommandoer er kun tilgængelige i **OpenCase Enterprise**.

    | Kommando | Beskrivelse |
    |----------|-------------|
    | `opencase:fetch-citizen-by-cpr` | Henter borgeroplysninger via CPR-nummer. |
    | `opencase:fetch-classifications` | Henter klassifikationer fra klassifikationstjenesten. |
    | `opencase:fetch-company-by-cvr` | Henter virksomhedsoplysninger via CVR-nummer. |
    | `opencase:fetch-company-by-name` | Søger virksomheder via navn. |
    | `opencase:has-digitalpost` | Kontrollerer om et CPR-nummer har Digital Post. |
    | `opencase:kombipost-afsend` | Sender Digital Post via KombiPostAfsend (SF1601). |
    | `opencase:search-citizens` | Søger borgere via CPR-tjenesten. |
    | `opencase:send-distribution-receipt` | Sender fordelingskvittering via distributionstjenesten. |
    | `opencase:sync-classifications` | Synkroniserer KLE-emneplan og klassifikationer. |
