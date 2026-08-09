# Adgang til API

OpenCase API anvender **Serviceplatformens integrationsmodel** til autentifikation og adgangskontrol i overensstemmelse med **SF1512**.

Alle API-kald udføres på vegne af en identificeret OpenCase-bruger, og adgangen til sager, dokumenter og øvrige ressourcer bestemmes af denne brugers rettigheder.

---

# Autentifikation

Et eksternt system skal autentificeres, før det kan kalde OpenCase API.

Autentifikationen sker ved hjælp af:

- Serviceplatformens Security Token Service (SF1512)
- gensidig TLS (mTLS)
- registrerede klientcertifikater

Kun systemer med et registreret klientcertifikat og en gyldig sikkerhedstoken kan få adgang til API'et.

---

# Registrering af Serviceplatformens certifikat

OpenCase skal kunne validere de tokens, der udstedes af Serviceplatformen.

Derfor skal den offentlige nøgle fra Serviceplatformens certifikat til adgangsstyring registreres.

Registreringen foretages med kommandoen:

```bash
php occ opencase:register-api-client
```

Administratoren bliver herefter bedt om at vælge certifikatfilen.

Når certifikatet er registreret, kan OpenCase validere signaturen på de modtagne sikkerhedstokens.

---

# Registrering af klientcertifikat

Det eksterne systems offentlige certifikat skal ligeledes registreres i OpenCase.

Registreringen foretages med den samme kommando:

```bash
php occ opencase:register-api-client
```

Under registreringen importeres klientcertifikatet, som herefter anvendes til validering af token.

Kun registrerede klientcertifikater kan etablere forbindelse til API'et.

---

# Tilknytning til en OpenCase-bruger

Et klientcertifikat skal tilknyttes en bruger i OpenCase.

Alle API-kald udføres med denne brugers identitet og rettigheder.

Det betyder, at API-klienten kun kan:

- læse de sager, brugeren har adgang til
- oprette dokumenter, hvis brugeren har skriverettigheder
- udføre handlinger, som brugeren er autoriseret til

Der gives aldrig flere rettigheder gennem API'et end brugeren selv har i OpenCase.

!!! info

    API'et anvender de samme regler for adgangskontrol som OpenCases brugergrænseflade. Der skelnes derfor ikke mellem adgang via webklienten og adgang via API'et.

---

# Adgangskontrol

Ved hvert API-kald kontrollerer OpenCase:

- at klientcertifikatet er registreret
- at forbindelsen er etableret via mTLS
- at den fremsendte sikkerhedstoken er gyldig
- at klientcertifikatet er knyttet til en OpenCase-bruger
- at brugeren har de nødvendige rettigheder til den ønskede handling

Hvis en af disse kontroller fejler, afvises anmodningen.

---

# God praksis

Det anbefales at:

- oprette en dedikeret integrationsbruger til hvert eksternt system
- tildele integrationsbrugeren de mindst nødvendige rettigheder
- registrere ét klientcertifikat pr. integration
- udskifte certifikater inden udløbsdato
- overvåge API-adgang via OpenCases transaktionslog

!!! tip

    Ved at anvende separate integrationsbrugere til forskellige systemer bliver det lettere at administrere rettigheder og efterfølgende spore API-aktivitet.

---

## Se også

- [API dokumentation](../api.md)
- [Installation – Basic-version](./installation-basic-version.md)
- [Fejlfinding](./fejlfinding.md)
- [Transaktionslog](./transaktionslog.md)