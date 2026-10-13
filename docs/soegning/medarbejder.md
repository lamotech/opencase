# Medarbejdere

Denne side beskriver, hvordan du søger efter medarbejdere i OpenCase.

Søgning efter medarbejdere gør det muligt hurtigt at finde en medarbejder og få et samlet overblik over medarbejderens placering i organisationen, tilknyttede sager og adgangsrettigheder.

Du kan søge på medarbejderens:

- navn
- organisation
- rolle

Når en medarbejder er valgt, vises en detaljeret oversigt med oplysninger om medarbejderen og vedkommendes relation til OpenCase.

![Søg efter medarbejder](../images/soegning/soeg-medarbejder.png)

---

## Udfør en søgning

Indtast et eller flere søgekriterier i søgefeltet og tryk **Enter** eller klik på **Søg**.

Du kan eksempelvis søge på:

- medarbejderens navn
- organisation
- rolle

Der vises en liste over de medarbejdere, der matcher søgekriterierne.

![Resultatliste](../images/soegning/soeg-medarbejder-resultat.png)

---

## Medarbejderoversigt

Når du vælger en medarbejder, åbnes medarbejderens oversigt.

Her kan du blandt andet se:

- medarbejderens navn
- organisation
- rolle

Desuden vises medarbejderens placering i organisationens organisationsstruktur.

![Medarbejderoversigt](../images/soegning/medarbejder-oversigt.png)

---

## Organisationsstruktur

På oversigtssiden vises, hvor medarbejderen er placeret i organisationen.

Det giver et hurtigt overblik over den organisatoriske tilknytning og gør det lettere at identificere den korrekte medarbejder.

---

## Fanen Opgaver

På fanen **Opgaver** vises de åbne opgaver, som medarbejderen skal udføre. Antallet af opgaver vises ved fanens navn.

Fanen samler to typer opgaver:

- **Workflowopgaver** – dokumenter i et [workflow](../dokumenter/workflow.md), hvor det er medarbejderens tur til at gennemse eller godkende dokumentet.
- **Påmindelser** – aktive [påmindelser](../dokumenter/paamindelser.md) på sager og dokumenter, som er tildelt medarbejderen.

For hver opgave vises:

| Kolonne | Beskrivelse |
|---------|-------------|
| **Frist** | Opgavens frist. Opgaver uden frist vises med teksten *Ingen frist*. |
| **Type** | *Gennemse* eller *Godkendelse* for workflowopgaver og *Påmindelse* for påmindelser. |
| **Opgave** | *Gennemse dokument* eller *Godkend eller afvis dokument* for workflowopgaver og påmindelsens titel for påmindelser. |
| **Sag** | Sagsnummer og titel på den sag, opgaven vedrører. |
| **Dokument** | Titel på det dokument, opgaven vedrører. |

Opgaverne er sorteret efter frist, så opgaven med den tidligste frist står øverst. Opgaver uden frist vises nederst.

Fristen vises med **rød fed skrift**, hvis den er overskredet.

![Fanen Opgaver](../images/soegning/medarbejder-opgaver.png)

### Åbn en opgave

Klik på en opgave for at åbne den:

- En workflowopgave åbner dokumentet på fanen **Workflow**.
- En påmindelse på et dokument åbner dokumentet.
- En påmindelse på en sag åbner sagen.

!!! info "Kun opgaver, du selv har adgang til"

    Listen viser kun opgaver på sager og dokumenter, som **du** har adgang til at se. Medarbejderen kan derfor have flere opgaver, end der vises på fanen.

---

## Fanen Sager

På fanen **Sager** vises de sager, hvor medarbejderen er registreret som part.

Herfra kan du:

- åbne en eksisterende sag
- se medarbejderens tilknytning til sagen
- oprette en ny sag på medarbejderen

Når der oprettes en ny sag fra medarbejderoversigten, registreres medarbejderen automatisk som **primær part** på sagen.

![Fanen Sager](../images/soegning/medarbejder-sager.png)

---

## Fanen Rettigheder

På fanen **Rettigheder** vises de adgangsrettigheder, medarbejderen har fået tildelt gennem den fælleskommunale adgangsstyring.

Her kan du blandt andet se:

- brugersystemrolle
- adgangsprofiler

Oversigten giver et samlet billede af medarbejderens adgang til OpenCase.

![Fanen Rettigheder](../images/soegning/medarbejder-rettigheder.png)

!!! info

    Rettighederne beregnes ud fra den fælleskommunale adgangsstyring og organisationens konfiguration.

---

## Fanen Manuelt tildelte sager

På fanen **Manuelt tildelte sager** vises de sager, hvor medarbejderen har fået adgang ved en manuel tildeling.

Dette omfatter sager, hvor en anden bruger har givet medarbejderen midlertidig eller permanent adgang ud over de rettigheder, der følger af adgangsstyringen.

Fra listen kan du åbne de enkelte sager.

![Fanen Manuelt tildelte sager](../images/soegning/medarbejder-manuelle-sager.png)

---

## Hvornår anvendes medarbejdersøgning?

Søgning efter medarbejdere er især nyttig, når du ønsker at:

- finde en bestemt medarbejder
- se medarbejderens organisatoriske placering
- få overblik over medarbejderens opgaver og frister
- få overblik over medarbejderens sager
- kontrollere medarbejderens adgangsrettigheder
- undersøge manuelt tildelte adgange

---

## God praksis

Hvis du er i tvivl om, hvorfor en medarbejder har adgang til en sag, kan du først kontrollere fanen **Rettigheder** og derefter fanen **Manuelt tildelte sager**.

Det gør det lettere at afgøre, om adgangen skyldes den fælleskommunale adgangsstyring eller en manuel tildeling.

!!! tip

    Medarbejdersøgning er et nyttigt værktøj for ledere og administratorer, som har behov for at få overblik over medarbejderes roller, sager og adgangsrettigheder.

---

## Se også

- [Adgang](../sager/adgang.md)
- [Andre sagsbehandlere](../sager/andre-sagsbehandlere.md)
- [Søg efter sager](sag.md)