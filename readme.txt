=== WooCommerce Kustom Product Export ===
Contributors: linthorwebbyra
Tags: woocommerce, kustom, sello, export, csv, products
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Utvecklat av [Linthor Webbyrå](https://linthor.com).

Exportera WooCommerce-produkter till CSV-filer redo för bulkimport i Kustom POS och Sello.

== Beskrivning ==

WooCommerce Kustom Product Export skapar CSV-filer med alla produkter i din WooCommerce-katalog i exakt det format Kustom POS och Sello kräver för bulkimport av produkter.

Funktioner:

* Tre exportformat: Kustom POS CSV, Sello CSV och Sello Excel (.xlsx), en knapp per format.
* Exporterar enkla produkter och variabla produkter (varje variant blir en egen rad).
* Kustom: fyra obligatoriska kolumner (product_name, price_including_vat, vat_rate, currency) plus dynamiska attributkolumner.
* Kustom: välj valfri egen attributkolumn i admin; den exporteras som sista kolumn och sparas som variantattribut (exempelvis condition med värdena new eller vintage). En varning visas om enkla produkter har flera värden på valt attribut (endast första värdet exporteras per rad).
* Sello: kolumner enligt Sellos officiella Excel-importdokumentation (Sku, Standard name, Standard description, Price, VAT rate, Property EAN, Property GTIN, Images, Group sku, Group type, Stock). Variationer grupperas via Group sku och Group type (color, model eller size).
* Priser exporteras med punkt som decimaltecken och utan tusentalsavgränsare.
* Momssats hämtas från produktens tax class, med butikens standardmomssats som fallback.
* Filnamn: kustom-products-YYYY-MM-DD-HHmm.csv respektive sello-products-YYYY-MM-DD-HHmm.csv, UTF-8, komma som avgränsare, LF som radslut.
* CSV-injection-skydd: cellvärden som börjar med =, +, - eller @ prefixas med en apostrof.
* Låg minnesanvändning: produkter hämtas och skrivs i omgångar om 100.

Sello-export:

* Exporterar samma produkter med Sellos kolumnnamn: Sku, Property EAN, Property GTIN, Standard name sv, Standard description sv, Quantity, Tax, Weight, Images, Brand, Group sku och Group type.
* Varianter med attributen color, size eller model grupperas automatiskt via Group sku och Group type.
* Vikt konverteras till gram utifrån butikens viktenhet.
* Momssatsen avrundas till närmsta giltiga Sello-värde (25, 12, 6, 0).
* Excel-export: klicka på "Exportera produkter till Sello Excel (.xlsx)" för att ladda ner en färdig Excel-arbetsbok (samma kolumner och data som CSV-versionen) som kan importeras till Sello direkt, utan att öppna och spara om filen i Excel. Filen genereras med det licensfria biblioteket XLSXWriter (MIT) som strömmar rad för rad, vilket håller minnesanvändningen låg även för stora kataloger.
* CSV-alternativet finns kvar för de som föredrar att granska filen som ren text.

== Installation ==

1. Ladda upp pluginmappen till katalogen /wp-content/plugins/.
2. Aktivera pluginet via menyn "Plugins" i WordPress.
3. Kontrollera att WooCommerce är installerat och aktiverat, annars visas en varning.
4. Gå till WooCommerce → Kustom-export.
5. Valfritt: justera filtren för utkast, privata produkter och produkter ur lager.
6. Valfritt: välj ett eget attribut i dropdownen "Egen attributkolumn" för Kustom-exporten.
7. Klicka på "Exportera produkter till Kustom POS CSV" eller "Exportera produkter till Sello CSV" för att ladda ner filen.

== Användning ==

1. Gå till WooCommerce → Kustom-export.
2. Sidan visar antal enkla produkter, antal varianter och totalt antal CSV-rader som kommer att exporteras.
3. Valfritt: välj ett attribut i dropdownen "Egen attributkolumn" för Kustom-exporten. Listan hämtas automatiskt från attribut i produktkatalogen.
4. Klicka på knappen för den plattform du vill exportera till.
5. CSV-filen laddas ner direkt till webbläsaren.

Kustom: importera filen via Kustom POS produktimport. VARNING: befintliga produkter i samma Kustom-butik ersätts vid import. Granska filen innan du importerar den.

Sello: logga in, öppna menyn upp till vänster, gå till Import / export, välj Excel i Import-sektionen och välj "Create new products from my file" (nya produkter) eller "Update products with my file" (uppdatera befintliga). Produkter matchas mot kolumnen Sku.

För Sello: Öppna den nedladdade CSV-filen i Excel och spara den som .xlsx. Importera sedan via Sello → Import/export → Excel. För att uppdatera befintliga produkter måste filen innehålla Sku, Id, Property EAN eller Property GTIN. Tomma celler raderar inte värden i Sello – använd Admin(delete) för att rensa ett fält. Grupptypen (color, size, model) kan inte ändras efter att gruppen skapats.

== Skärmdumpar ==

(Ännu inte tillgängliga.)

== Changelog ==

= 1.2.0 =
* Nytt exportformat: Sello Excel (.xlsx). Knappen "Exportera produkter till Sello Excel (.xlsx)" laddar ner en färdig Excel-arbetsbok som kan importeras till Sello direkt.
* XLSXWriter-biblioteket (MIT, licensfritt, en fil, inga externa beroenden) läggs med i includes/lib/ och strömmar rad för rad för låg minnesanvändning.
* Generatorerna omfaktorerade med en gemensam raditerator (get_rows_iter) som både CSV- och XLSX-exporten använder.

= 1.1.0 =
* Ny exportplattform: Sello, med kolumner enligt Sellos officiella Excel-importdokumentation och variantgruppering via Group sku/Group type.
* Gemensam basklass för CSV-generatorer; en knapp per plattform i admin.
* Exportfiltren delas av båda plattformarna.

= 1.0.0 =
* Första versionen. Export av enkla produkter och variabler till Kustoms produktimportformat, med exportfilter och modernt admin-gränssnitt.

== Frequently Asked Questions ==

= Vem ligger bakom pluginet? =
Pluginet är utvecklat av Linthor Webbyrå (https://linthor.com).

= Vilka kolumner finns i Kustom-filen? =
Som standard endast de fyra obligatoriska: product_name, price_including_vat, vat_rate, currency, plus dynamiska attributkolumner för varianter. Valfria kolumner (sku, category, ean, image_url, stock_status) aktiveras först när formatet verifierats mot Kustoms officiella exempel-CSV-filer, eftersom Kustom tolkar extra kolumner som variantattribut.

= Vilka kolumner finns i Sello-filen? =
Sku, Standard name, Standard description, Price, VAT rate, Property EAN, Property GTIN, Images, Group sku, Group type och Stock. Namnen följer Sellos officiella dokumentation för Excel-import ("Supported fields"). Sku krävs för att Sello ska kunna matcha produkter vid uppdatering.

= Hur beräknas priset? =
Kustom-filen exporterar priset inklusive moms. Sello-filen exporterar priset exklusive moms (Price) tillsammans med momssatsen (VAT rate), vilket är konventionellt för marknadsplatsimport. Båda baseras på produktens pris och tillämplig momssats, oavsett om butiken visar priser inklusive eller exklusive moms.

= Hur grupperas varianter i Sello? =
Varje variation får samma Group sku (förälderns SKU, eller härlett prefix om föräldern saknar SKU) och en Group type som väljs utifrån förälderns första attribut: color för färg, size för storlek, annars model. Sello kräver att grupptypen är color, model eller size.
