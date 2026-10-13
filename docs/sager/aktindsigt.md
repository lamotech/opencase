# Aktindsigt

OpenCase understøtter behandling af aktindsigtsanmodninger efter de gældende regler.

En aktindsigtsanmodning behandles på den sag, som anmodningen vedrører. OpenCase understøtter hele processen fra registrering af anmodningen til udvælgelse af dokumenter, vurdering, maskering og udarbejdelse af den endelige aktindsigtspakke.

På sagens **Aktindsigt**-fane kan du se alle aktindsigtsanmodninger, der er registreret på sagen.

![Fanen Aktindsigt](../images/sager/aktindsigt-sag-fane.png)

---

## Opret en aktindsigtsanmodning

Vælg **Ny aktindsigtsanmodning** i sagens kontekstmenu.

![Kontekstmenu - Ny aktindsigtsanmodning](../images/sager/aktindsigt-menu.png)

Ved oprettelsen registreres:

- typen af aktindsigt
- oplysninger om anmoder

### Type

Der kan vælges mellem:

- **Offentlighedsloven**
- **Forvaltningsloven**
- **GDPR**

Valget afgør, hvilket regelsæt der danner grundlag for behandlingen af anmodningen.

### Anmoder

Registrér oplysninger om den person eller organisation, der har anmodet om aktindsigt.

Eksempelvis:

- navn
- adresse
- e-mail
- telefonnummer

![Dialog Ny aktindsigtsanmodning](../images/sager/aktindsigt-anmodning.png)

---

## Behandlingsstatus

En aktindsigtsanmodning gennemgår en række statusser.

Status ændres fra aktindsigtsanmodningens **kontekstmenu**.

| Status | Beskrivelse |
|---------|-------------|
| **Modtaget** | Anmodningen er registreret. |
| **Start indsamling** | Udvælg de dokumenter, der skal indgå i vurderingen. |
| **Start vurdering** | Vurder hvert dokument og beslut, om det skal udleveres. |
| **Start maskering** | Maskér fortrolige oplysninger i dokumenterne. |
| **Send til godkendelse** | Udarbejd afgørelsen og afslut behandlingen. |
| **Afsendt** | Aktindsigten er sendt til anmoder. |

Status giver et hurtigt overblik over, hvor langt behandlingen er.

---

## Indsamling af dokumenter

Når status ændres til **Start indsamling**, udvælges de dokumenter, der er relevante for aktindsigtsanmodningen.

Kun de udvalgte dokumenter indgår i den videre behandling.

![Udvælg dokumenter](../images/sager/aktindsigt-indsamling.png)

---

## Vurdering

Når alle relevante dokumenter er udvalgt, ændres status til **Start vurdering**.

For hvert dokument skal det vurderes, om dokumentet:

- skal medtages i aktindsigten
- skal udelades

Hvis et dokument udelades, kan der angives en begrundelse.

Eksempler på begrundelser kan være:

- Dokumentet er undtaget efter lovgivningen.
- Dokumentet indeholder fortrolige oplysninger.
- Dokumentet er ikke omfattet af anmodningen.

Alle vurderinger registreres som en del af behandlingen.

![Vurder dokumenter](../images/sager/aktindsigt-vurdering.png)

---

## Maskering

Hvis et dokument indeholder fortrolige oplysninger, kan oplysningerne maskeres, inden dokumentet udleveres.

Eksempler på oplysninger, der ofte maskeres:

- CPR-numre
- kontonumre
- personfølsomme oplysninger
- andre fortrolige oplysninger

### Automatisk maskering

!!! info "Enterprise"
    Hvis administratoren har sat maskeringstjenesten op (Enterprise-versionen), vises knappen **Foreslå maskering** ud for filerne under fanen **Maskering**. Den fungerer for PDF-, Word-, Excel-, PowerPoint- og OpenDocument-filer.

    ![Foreslå maskering](../images/sager/aktindsigt-foreslaa-knappen.png)

    1. Klik **Foreslå maskering**. Filen gennemsøges efter de maskeringsregler, administratoren har sat op – fra start CPR-numre, e-mailadresser, telefonnumre og kontonumre. Hvert fund vises med regel og eventuel hjemmel.
    ![Foreslå maskering](../images/sager/aktindsigt-foreslaa-maskering.png)
    2. Gennemgå forslagene. Alle fund er markeret på forhånd – fjern markeringen ved fund, der ikke skal maskeres.
    3. Tilføj selv tekst, der skal maskeres, f.eks. navne, i feltet **Tilføj tekst der skal maskeres**. Teksten maskeres alle steder i filen.
    4. Klik **Anvend maskering**.

    Resultatet gemmes som filens maskerede version. Det er altid en PDF, også når originalen er et Word- eller Excel-dokument. Teksten under de sorte felter er fjernet fra filen og kan ikke kopieres frem.

    ![Maskeret pdf](../images/sager/aktindsigt-maskeret-pdf.png)


    !!! note

        Tjenesten finder kun det, maskeringsreglerne beskriver. Navne, helbredsoplysninger og andre fortrolige oplysninger skal du som regel selv tilføje. Sider uden tekst (f.eks. scannede sider) kan ikke gennemsøges – dialogen advarer, hvis filen har sådanne sider.

### Manuel maskering

Maskering kan også foretages ved at:

1. downloade dokumentets fil
2. maskere de relevante oplysninger
3. uploade den maskerede version

Den maskerede fil anvendes herefter i aktindsigten.

!!! warning

    Kontroller altid den maskerede fil, inden aktindsigten sendes. Maskeringen skal være permanent og må ikke kunne fjernes af modtageren.


![Vurder dokumenter](../images/sager/aktindsigt-maskering.png)

---

## Godkendelse

Når dokumenterne er vurderet og eventuelt maskeret, ændres status til **Send til godkendelse**.

I forbindelse med godkendelsen registreres afgørelsen.

Der kan vælges mellem:

- **Fuld aktindsigt**
- **Delvis aktindsigt**
- **Afslag**

Der kan samtidig skrives en begrundelse eller afgørelsestekst, som indgår i den endelige afgørelse.

![Afgørelse](../images/sager/aktindsigt-afgørelse.png)

Når afgørelsen er godkendt, er aktindsigten klar til afsendelse.

![Godkend aktindsigt](../images/sager/aktindsigt-godkendelse.png)

---

## Aktindsigtspakke

Efter godkendelsen kan der dannes en ZIP-fil med hele aktindsigten.

Pakken indeholder:

- de dokumenter, der skal udleveres
- en aktliste
- afgørelsen

ZIP-filen kan herefter sendes til anmoder via organisationens normale kommunikationskanaler.

![Download zip](../images/sager/aktindsigt-download-zip.png)

---

## Afsendelse

Når aktindsigten er sendt til anmoder, ændres status til **Afsendt**.

Aktindsigtsanmodningen forbliver registreret på sagen, så hele behandlingsforløbet kan dokumenteres senere.

![Afsendt](../images/sager/aktindsigt-afsendt.png)

---

## Oversigt over aktindsigtsanmodninger

På sagens **Aktindsigt**-fane vises alle aktindsigtsanmodninger, der er registreret på sagen.

Herfra kan du blandt andet:

- se den aktuelle status
- åbne en aktindsigtsanmodning
- fortsætte behandlingen
- hente den færdige aktindsigtspakke

Det giver et samlet overblik over alle aktindsigtsforløb på sagen.

---

## God praksis

Det anbefales at:

- udvælge alle relevante dokumenter, inden vurderingen påbegyndes
- dokumentere begrundelsen for udeladte dokumenter
- kontrollere maskerede dokumenter grundigt
- gennemlæse afgørelsen inden godkendelse
- gemme den endelige aktindsigtspakke som dokumentation for det gennemførte forløb

---

## Se også

- [Dokumenter](../dokumenter/index.md)
- [Sagslog](../sager/sagslog.md)
- [Digital post](../dokumenter/digital-post.md)