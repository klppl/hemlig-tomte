# Secret Santa / Hemlig Tomte

En enkel och användarvänlig Secret Santa-presentutbyteshanteringssystem byggt med PHP. Perfekt för att organisera presentutbyten med vänner, familj eller kollegor.

## Funktioner

- 🎄 **Användarhantering**: Skapa och hantera deltagare
- 🎁 **Skapa dragningar**: Skapa flera namngivna dragningar (t.ex. "2025", "2026")
- 🔐 **Säker autentisering**: Lösenordsskyddade användarkonton
- 💰 **Budget & Deadline**: Sätt maximal presentkostnad och deadlines
- 📝 **Intresselistor**: Deltagare kan dela hobbys och intressen
- ✅ **Köpstatus**: Markera när presenter har köpts
- 🌍 **Flerspråkig**: Stöd för svenska och engelska
- 🎨 **Festlig design**: Vacker jul-tematiserad användargränssnitt

## Krav

- PHP 7.4 eller högre
- Webbserver (Apache/Nginx)
- Skrivrättigheter för `data/`-katalogen

## Installation

1. Klona eller ladda ner detta repository
2. Se till att PHP-sessioner är aktiverade
3. Se till att `data/`-katalogen är skrivbar:
   ```bash
   chmod 755 data/
   ```
4. Öppna via din webbläsare

## Första gången

Vid första besöket kommer du att bli ombedd att skapa ett admin-konto:
- Användarnamn: `admin` (fastställt)
- Lösenord: Välj ditt eget lösenord (ex hunter2)

## Användning

### Adminpanel

1. Logga in med dina admin-uppgifter
2. **Lägg till användare**: Skapa deltagarkonton
3. **Skapa dragning**: 
   - Ange dragningsnamn (t.ex. "2025")
   - Sätt valfri budget och deadline
   - Välj deltagare
   - Klicka på "Kör dragning"
4. **Hantera dragningar**: Aktivera, arkivera eller radera dragningar
5. **Visa status**: Se alla tilldelningar, intressen och köpstatus

### Användarvy

1. Logga in med ditt användarnamn och lösenord
2. Visa din tilldelade mottagare
3. Se mottagarens intressen/hobbys
4. Uppdatera dina egna intressen för att hjälpa din Secret Santa
5. Markera när du har köpt din present

## Filstruktur

```
santa/
├── index.php      # Inloggningssida & admin-installation
├── admin.php      # Adminpanel
├── view.php       # Användarvy
├── draw.php       # Dragningsskapande-hanterare
├── inc.php        # Översättningar & hjälpfunktioner
└── data/
    ├── users.json # Användarkonton
    └── pairs.json # Dragningsdata
```

## Säkerhetsanteckningar

- Admin-användaren kan inte raderas
- Admin-användaren kan inte delta i dragningar
- Lösenord hashas med PHP:s `password_hash()`
- Sessionbaserad autentisering
- Ändra standardlösenordet för admin efter första installationen

## Anpassning

- Redigera `inc.php` för att ändra översättningar
- Modifiera CSS i varje PHP-fil för att ändra styling
- Justera budget/deadline-funktioner efter behov

## Licens

Detta projekt släpps till allmänheten under [Unlicense](UNLICENSE). Du är fri att använda, modifiera, distribuera och sälja denna programvara för vilket ändamål som helst, kommersiellt eller icke-kommersiellt, utan några begränsningar.

## Support

För problem eller frågor, kontrollera kodkommentarerna eller modifiera efter behov för ditt användningsfall.
