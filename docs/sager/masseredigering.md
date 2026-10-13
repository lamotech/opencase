# Masseredigering

Masseredigering gør det muligt at opdatere et bestemt felt på **mange sager samtidig**, i stedet for at åbne og rette hver sag enkeltvis. Du angiver et sæt søgekriterier, som udpeger de sager, der skal opdateres, og de felter og værdier, der skal sættes på dem.

Masseredigeringer behandles i baggrunden og gennemføres typisk inden for en time efter, de er gemt.

---

## Hvor finder du masseredigering

Under menupunktet **Sager** i venstre navigation findes underpunktet **Masseredigering**.

![Menupunktet Masseredigering under Sager](../images/sager/masseredigering-menu.png)

Siden viser en liste over alle masseredigeringer, du selv har oprettet, nyeste øverst.

![Liste over masseredigeringer](../images/sager/masseredigering-liste.png)

For hver masseredigering vises:

| Kolonne | Beskrivelse |
|---------|-------------|
| **Titel** | Titlen, du selv gav masseredigeringen. Klik på titlen for at se detaljerne — se afsnittet **Se detaljer** nedenfor. |
| **Antal** | Antal sager, der matchede søgekriterierne, da masseredigeringen blev gemt. |
| **Succes** | Antal sager, der er opdateret uden fejl. |
| **Fejlet** | Antal sager, der fejlede under opdatering. Er tallet større end 0, kan der klikkes på det — se afsnittet **Se fejlede sager** nedenfor. |
| **Status** | Se afsnittet **Status og forløb** nedenfor. |
| **Oprettet** | Tidspunktet, masseredigeringen blev gemt. |

---

## Opret en ny masseredigering

Klik på knappen **Ny Masseredigering** under overskriften på listesiden.

![Knappen Ny Masseredigering](../images/sager/masseredigering-ny-knap.png)

Øverst på siden angives en **titel** for masseredigeringen. Titlen bruges udelukkende til at genkende masseredigeringen i listen bagefter og har ingen betydning for, hvilke sager der opdateres.

![Titelfelt og Gem-knap](../images/sager/masseredigering-ny-header.png)

Under titelfeltet findes to faner: **Søgekriterier** og **Felter der skal opdateres**.

### Fanen Søgekriterier

På fanen **Søgekriterier** angives, hvilke sager der skal indgå i masseredigeringen. Kriterierne fungerer på samme måde som på siden **Søg → Søg efter sager**:

| Felt | Beskrivelse |
|------|-------------|
| **Søgetekst** | Søger i sagsnummer og titel. |
| **Organisation** | Afgræns til én organisation. |
| **År** | Sagens oprettelsesår. |
| **Status** | Sagens status (fx Åben, Lukket). |
| **Sagstype** | Sagens sagstype. |
| **KLE-nummer** | Sagens klassifikation. |
| **Følsomhed** | Sagens følsomhedsniveau. |
| **Handlingsfacet** | Sagens handlingsfacet. |
| **Indsigtsgrad** | Sagens indsigtsgrad. |
| **Ansvarlig** | Sagens ansvarlige sagsbehandler. |

Klik på **Søg** for at se, hvor mange og hvilke sager der matcher de angivne kriterier. Resultatet vises som en liste nederst på siden.

![Fanen Søgekriterier med resultatliste](../images/sager/masseredigering-soegekriterier.png)

!!! tip

    Søg gerne igennem et par gange, og kig efter i resultatlisten, at det er de rigtige sager, der bliver ramt, inden du går videre til fanen **Felter der skal opdateres**. Der er ingen fortrydelsesmulighed, når masseredigeringen først er gemt og behandlet.

### Fanen Felter der skal opdateres

På fanen **Felter der skal opdateres** vælges, hvilke felter der skal ændres på alle de sager, der matcher søgekriterierne. Hvert felt har en kontakt (til/fra) foran sig — kun de felter, hvor kontakten er slået **til**, bliver ændret. Felter, hvor kontakten er slået fra, røres ikke.

| Felt | Beskrivelse |
|------|-------------|
| **Titel** | Sagens titel. |
| **Organisation** | Sagens organisation. |
| **KLE-nummer** | Sagens klassifikation. |
| **Følsomhed** | Sagens følsomhedsniveau. |
| **Handlingsfacet** | Sagens handlingsfacet. |
| **Indsigtsgrad** | Sagens indsigtsgrad. |
| **Ansvarlig** | Sagens ansvarlige sagsbehandler. |
| **Sagsresume** | Sagens resume. |

![Fanen Felter der skal opdateres](../images/sager/masseredigering-felter.png)

!!! warning "Vælg felter med omtanke"

    Alle sager, der matcher søgekriterierne, får de samme nye værdier på de valgte felter. Sørg for, at søgekriterierne er præcise nok til, at kun de ønskede sager rammes.

---

## Gem og bekræft

Når søgekriterier og felter er udfyldt, klikkes på **Gem** øverst på siden.

Inden masseredigeringen gemmes, vises en bekræftelsesdialog med et resume:

- titlen på masseredigeringen
- antal sager, der opdateres
- de felter, der opdateres, og deres nye værdier

![Bekræft masseredigering-dialogen](../images/sager/masseredigering-bekraeft-dialog.png)

Klik **OK** for at gemme masseredigeringen, eller **Annuller** for at gå tilbage og rette i kriterier eller felter.

Når masseredigeringen er gemt, føres du tilbage til listen, hvor den nye masseredigering nu fremgår med status **Afventer**.

---

## Status og forløb

En masseredigering gennemgår følgende statusser:

| Status | Beskrivelse |
|--------|-------------|
| **Oprettet** | Masseredigeringen er ved at blive gemt. |
| **Afventer** | Masseredigeringen er gemt og venter på at blive behandlet. |
| **Behandler** | Masseredigeringen er i gang med at blive gennemført. |
| **Behandlet** | Alle sager er forsøgt opdateret. Se kolonnerne **Succes** og **Fejlet** for resultatet. |
| **Annulleret** | Masseredigeringen er annulleret, før den blev færdigbehandlet — se afsnittet **Annuller en masseredigering** nedenfor. |

Masseredigeringer behandles automatisk af et baggrundsjob, der kører **hver time**. Der skal derfor ikke foretages sig noget, efter en masseredigering er gemt — den skifter selv status, efterhånden som den behandles.

### Se detaljer

Klik på **titlen** på en masseredigering i listen for at se, hvilke felter der blev sat, og med hvilke værdier.

![Detaljedialog for en masseredigering](../images/sager/masseredigering-detaljer-dialog.png)

### Se fejlede sager

Hvis en eller flere sager fejlede under behandlingen, vises antallet i kolonnen **Fejlet** som et klikbart tal. Klik på tallet for at se en liste over de fejlede sager med sagsnummer og fejlmeddelelse.

![Dialog med fejlede sager](../images/sager/masseredigering-fejlede-dialog.png)

Klik på et sagsnummer i listen for at åbne sagen i en ny fane og undersøge årsagen til fejlen.

---

## Annuller en masseredigering

Så længe en masseredigering har status **Oprettet**, **Afventer** eller **Behandler**, kan den annulleres via knappen **Annuller** ud for masseredigeringen i listen.

![Knappen Annuller](../images/sager/masseredigering-annuller.png)

Der bedes om en bekræftelse, inden masseredigeringen annulleres.

!!! info "Allerede opdaterede sager berøres ikke"

    Bliver en masseredigering annulleret, mens den er i gang (status **Behandler**), forbliver de sager, der allerede er nået at blive opdateret, opdaterede. Kun de sager, der endnu ikke var behandlet, springes over.

---

## God praksis

- Brug fanen **Søgekriterier** og knappen **Søg** til at kontrollere antallet og listen af sager, **inden** felterne udfyldes og masseredigeringen gemmes.
- Slå kun kontakten til på de felter, der rent faktisk skal ændres — felter med kontakten slået fra røres ikke.
- Giv masseredigeringen en titel, der gør det let at genkende den i listen senere, fx med dato eller formålet med ændringen.
- Tjek altid kolonnerne **Succes** og **Fejlet**, når status er skiftet til **Behandlet**, og undersøg eventuelle fejlede sager via fejllisten.

---

## Se også

- [Masseredigering af dokumenter](../dokumenter/masseredigering.md)
- [Søgning efter sager](../soegning/sag.md)
- [Tillægsfelter](./tillaegsfelter.md)
