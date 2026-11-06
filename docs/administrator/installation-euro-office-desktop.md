# Installation – Euro-Office plugin

OpenCase-pluginet til Euro-Office leveres som én fil, `opencase-plugin.zip`, som bruges til alle installationer: Windows, Linux og Document Server. Zip-filen indeholder pluginet og de tre installationsscripts:

| Script | Bruges til |
|---|---|
| `install-plugin.ps1` | Euro-Office på Windows |
| `install-plugin.sh` | Euro-Office på Linux |
| `install-server.sh` | Document Server (Ubuntu) |

## Hvor skal pluginet installeres?

Pluginet skal installeres to steder:

- **På den Document Server, som OpenCase bruger.** Den installation bruges, når et dokument åbnes fra OpenCase, både i Euro-Office på computeren og i browseren. Euro-Office bruger ikke det lokalt installerede plugin til dokumenter fra en Document Server. Én installation på serveren dækker alle brugere.
- **I Euro-Office på den enkelte computer.** Den installation bruges til filer, der ligger lokalt på computeren, fx til **Gem i OpenCase**.

Pluginet kræver Euro-Office 8.1 eller nyere.

!!! warning "Angiv altid adressen på OpenCase"

    Angiv adressen på jeres OpenCase-server med `--url` (Linux og server) eller `-Url` (Windows), når pluginet installeres. Ellers bruger pluginet den adresse, der er indbygget i zip-filen. Brugerne kan selv ændre adressen under **Indstillinger** på fanen OpenCase.

---

## Hent pluginet

Pluginet ligger som en release på GitHub:

`https://github.com/<organisation>/<repository>/releases`

Hent filen `opencase-plugin.zip` fra den ønskede release. Den nyeste version kan altid hentes på denne adresse:

`https://github.com/<organisation>/<repository>/releases/latest/download/opencase-plugin.zip`

Versionsnummeret står i release-navnet og i `config.json` i zip-filen.

Hvis release-noterne angiver en kontrolsum (SHA-256), kan du kontrollere filen:

- Windows: `Get-FileHash opencase-plugin.zip`
- Linux: `sha256sum opencase-plugin.zip`

---

## Windows

Pluginet installeres for den bruger, der kører scriptet, i mappen
`%LOCALAPPDATA%\Euro-Office\DesktopEditors\data\sdkjs-plugins\{0CCBED17-288E-417C-8A84-26832C7AF9D2}\`.
Der kræves ikke administratorrettigheder.

### Installation på én computer

1. Hent `opencase-plugin.zip`, og pak den ud, fx til `Downloads\opencase-plugin`.
2. Åbn **PowerShell** i den udpakkede mappe.
3. Kør:

    ```powershell
    powershell.exe -ExecutionPolicy Bypass -File .\install-plugin.ps1 -Url https://opencase.kommune.dk/
    ```

4. Genstart Euro-Office.

Fanen **OpenCase** vises nu i båndet.

Du kan også hente og installere direkte fra PowerShell:

```powershell
$zip = "$env:TEMP\opencase-plugin.zip"
Invoke-WebRequest "https://github.com/<organisation>/<repository>/releases/latest/download/opencase-plugin.zip" -OutFile $zip
Expand-Archive $zip "$env:TEMP\opencase-plugin" -Force
powershell.exe -ExecutionPolicy Bypass -File "$env:TEMP\opencase-plugin\install-plugin.ps1" -Url https://opencase.kommune.dk/
```

### Udrulning med Intune eller logon-script

Læg `opencase-plugin.zip` og `install-plugin.ps1` i pakken, og kør:

```
powershell.exe -ExecutionPolicy Bypass -File install-plugin.ps1 -Zip opencase-plugin.zip -Url https://opencase.kommune.dk/
```

- Kør scriptet **i brugerens kontekst** (i Intune: *Install behavior* = *User*). Pluginet installeres pr. bruger. Kører scriptet som SYSTEM, havner pluginet i systemprofilen, hvor brugerne ikke kan se det.
- Scriptet afslutter med exit-kode **0**, når installationen lykkes, og **1** ved fejl.
- **Detektion:** kontrollér, at filen
  `%LOCALAPPDATA%\Euro-Office\DesktopEditors\data\sdkjs-plugins\{0CCBED17-288E-417C-8A84-26832C7AF9D2}\config.json`
  findes og indeholder den forventede `"version"`.
- **Afinstallation:** `install-plugin.ps1 -Uninstall`, også i brugerens kontekst.

### Opdatering og afinstallation

- **Opdatering:** kør installationen igen med den nye zip-fil. Den gamle version fjernes automatisk.
- **Afinstallation:** kør `install-plugin.ps1 -Uninstall`.

Genstart Euro-Office bagefter.

---

## Linux

Pluginet installeres for den bruger, der kører scriptet, i
`~/.local/share/euro-office/desktopeditors/sdkjs-plugins/{0CCBED17-288E-417C-8A84-26832C7AF9D2}/`.
Der kræves ikke root-rettigheder, og scriptet skal **ikke** køres med `sudo`.

### Installation

1. Start Euro-Office én gang, og luk programmet igen. Så findes Euro-Offices datamappe, som scriptet leder efter.
2. Installér `unzip`, hvis det mangler: `sudo apt install unzip`.
3. Hent og installér pluginet:

    ```sh
    cd ~/Downloads
    wget https://github.com/<organisation>/<repository>/releases/latest/download/opencase-plugin.zip
    unzip -o opencase-plugin.zip -d opencase-plugin
    sh opencase-plugin/install-plugin.sh --url https://opencase.kommune.dk/
    ```

4. Start Euro-Office.

Scriptet finder selv Euro-Offices datamappe, også ved en Flatpak-installation (`~/.var/app/…`). Finder det ikke mappen, skriver det en advarsel og bruger standardmappen. Ligger Euro-Offices plugins et andet sted, kan mappen angives med `--dir`:

```sh
sh opencase-plugin/install-plugin.sh --url https://opencase.kommune.dk/ --dir ~/.local/share/<mappe>/desktopeditors/sdkjs-plugins
```

### Opdatering og afinstallation

- **Opdatering:** hent den nye zip-fil, og kør installationen igen. Den gamle version fjernes automatisk.
- **Afinstallation:** `sh opencase-plugin/install-plugin.sh --uninstall`.

Genstart Euro-Office bagefter.

---

## Document Server (Ubuntu)

Pluginet installeres i Document Serverens `sdkjs-plugins`-mappe, så det er tilgængeligt for alle brugere, når de åbner dokumenter fra OpenCase.

### Installation

Log ind på serveren, og kør:

```sh
sudo apt install unzip
cd /tmp
wget https://github.com/<organisation>/<repository>/releases/latest/download/opencase-plugin.zip
unzip -o opencase-plugin.zip -d opencase-plugin
sudo sh opencase-plugin/install-server.sh --url https://opencase.kommune.dk/
```

Scriptet:

- finder selv Document Serverens mappe (fx `/var/www/onlyoffice/documentserver` eller `/var/www/euro-office/documentserver`)
- kopierer pluginet til `sdkjs-plugins/{0CCBED17-288E-417C-8A84-26832C7AF9D2}/` og giver filerne samme ejer som Document Serverens øvrige filer
- tømmer Document Serverens cache, så editorerne henter pluginet

Finder scriptet ikke Document Serveren, kan mappen angives med `--dir`. Det er mappen, der indeholder `web-apps` og `sdkjs`:

```sh
sudo sh opencase-plugin/install-server.sh --url https://opencase.kommune.dk/ --dir /var/www/onlyoffice/documentserver
```

Brugerne skal lukke og åbne dokumentet igen for at se fanen **OpenCase**.

!!! info "Docker"

    Kører Document Serveren i Docker, skal pluginet ligge i containerens `sdkjs-plugins`-mappe. Montér fx mappen `opencase-plugin` som et volume på `/var/www/onlyoffice/documentserver/sdkjs-plugins/{0CCBED17-288E-417C-8A84-26832C7AF9D2}`, så pluginet ikke forsvinder, når containeren opdateres.

### Opdatering og afinstallation

- **Opdatering:** hent den nye zip-fil, og kør installationen igen. Den gamle version fjernes automatisk.
- **Afinstallation:** `sudo sh opencase-plugin/install-server.sh --uninstall`.

!!! note

    En opdatering af Document Serveren kan fjerne pluginet. Kontrollér efter en opdatering, at fanen **OpenCase** stadig vises, og installér pluginet igen, hvis den mangler.

---

## Fejlfinding

| Problem | Løsning |
|---|---|
| Fanen **OpenCase** vises ikke i dokumenter fra OpenCase | Pluginet mangler på Document Serveren. Installér det dér, og åbn dokumentet igen |
| Fanen **OpenCase** vises ikke i lokale filer | Pluginet mangler i Euro-Office på computeren. Installér det, og genstart Euro-Office |
| Fanen vises, men pluginet forbinder til den forkerte server | Ret adressen under **Indstillinger** på fanen, eller installér igen med den rigtige `--url` / `-Url` |
| Windows: pluginet er installeret, men vises ikke for brugeren | Scriptet er kørt som SYSTEM eller som en anden bruger. Kør det i brugerens kontekst |
| Linux: advarslen "no Euro-Office data folder found" | Start Euro-Office én gang før installationen, eller angiv mappen med `--dir` |
