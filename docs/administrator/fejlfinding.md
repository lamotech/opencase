# Fejlfinding

Hvis der opstår fejl i OpenCase, findes der en række logfiler og kommandolinjeværktøjer, som kan hjælpe med at identificere og løse problemet.

Denne side beskriver de mest anvendte værktøjer til fejlfinding og vedligeholdelse.

---

# Typiske fejl

## Organisationer synkroniseres ikke

Kontrollér:

- systemcertifikatet
- Serviceaftalen for SF1500
- organisations-endpoints
- at synkroniseringsjobbet er aktiveret

Prøv eventuelt:

```bash
php occ opencase:fetch-orgs
```

eller

```bash
php occ opencase:sync-org
```

---

## Brugere mangler adgang

Kontrollér:

- brugerens jobfunktionsroller
- SAML-konfigurationen
- Context Handler
- at adgangsstyring er aktiveret

Genberegn derefter rettighederne:

```bash
php occ opencase:privileges:recalculate
```

---

## Fuldtekstsøgning virker ikke

Kontrollér:

- Elasticsearch kører
- FullTextSearch-apps er installeret
- Elasticsearch-platformen er konfigureret

Genindeksér derefter OpenCase:

```bash
php occ opencase:reindex
```

---

## Virtuelle filer mangler

Hvis filer eller mapper ikke vises korrekt i Nextcloud, kan den virtuelle filstruktur genopbygges.

Kør:

```bash
php occ opencase:filecache:sync
```

---

# God praksis

Ved fejlfinding anbefales følgende fremgangsmåde:

1. Kontroller `nextcloud.log`.
2. Aktivér eventuelt trace-logning.
3. Genskab fejlen.
4. Undersøg `opencase.log`.
5. Deaktivér trace-logning igen.
6. Udfør relevante OCC-kommandoer for at verificere eller reparere systemet.

!!! tip

    De fleste fejl kan identificeres ved at kombinere oplysningerne fra `nextcloud.log`, `opencase.log` og de relevante OCC-kommandoer. Det anbefales altid at undersøge logfilerne, inden der foretages ændringer i konfigurationen.

---

## Se også

- [Installation – Basic-version](./installation-basic-version.md)
- [Logfiler](./logfiler.md)
- [Konfiguration](./konfiguration.md)
- [OCC Kommandoer](./occ-kommandoer.md)