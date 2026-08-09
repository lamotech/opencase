# Logfiler

Når der opstår fejl, bør logfilerne være det første sted, der undersøges.

## Nextcloud-log

Den primære logfil findes i Nextclouds datamappe.

Typisk:

```text
<data-mappe>/nextcloud.log
```

Her registreres fejl fra både Nextcloud og installerede apps, herunder OpenCase.

---

## OpenCase Trace-log

OpenCase har desuden sin egen detaljerede trace-log.

Logfilen findes i:

```text
<data-mappe>/opencase.log
```

Trace-loggen indeholder detaljerede oplysninger om OpenCases interne behandling og er særligt nyttig ved fejlsøgning af integrationer og baggrundsprocesser.

---

## Aktivér trace-logning

Trace-logning aktiveres med:

```bash
php occ opencase:trace-enable
```

Når fejlsøgningen er afsluttet, bør trace-logning deaktiveres igen:

```bash
php occ opencase:trace-disable
```

!!! warning

    Trace-logning genererer væsentligt flere logoplysninger end normal drift og kan påvirke systemets ydeevne. Det anbefales derfor kun at aktivere trace-logning midlertidigt på produktionsmiljøer.

---