=== WooCommerce Kustom Product Export ===
Contributors: linthorwebbyra
Tags: woocommerce, kustom, sello, export, csv, products
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Utvecklat av [Linthor Webbyrå](https://linthor.com).

Exportera WooCommerce-produkter till en CSV-fil redo för bulkimport i Kustom POS och Sello.

== Beskrivning ==

WooCommerce Kustom Product Export skapar en CSV-fil med alla produkter i ditt WooCommerce-catalog i exakt det format Kustom POS kräver för bulkimport av produkter.

Funktioner:

* Exporterar enkla produkter och variabla produkter (varje variant blir en egen rad).
* Fyra obligatoriska kolumner: product_name, price_including_vat, vat_rate, currency.
* Attributkolumner (exempelvis size, color) läggs till automatiskt utifrån de attribut som faktiskt används av varianterna.
* Priser exporteras alltid inklusive moms, med punkt som decimaltecken och utan tusentalsavgränsare.
* Momssats hämtas från produktens tax class, med butikens standardmomssats som fallback.
* Filnamn: kustom-products-YYYY-MM-DD-HHmm.csv, UTF-8, komma som avgränsare, LF som radslut.
* CSV-injection-skydd: cellvärden som börjar med =, +, - eller @ prefixas med en apostrof.
* Låg minnesanvändning: produkter hämtas och skrivs i omgångar om 100.

Sello-export:

* Exporterar samma produkter med Sellos kolumnnamn: Sku, Property EAN, Property GTIN, Standard name sv, Standard description sv, Quantity, Tax, Weight, Images, Brand, Group sku och Group type.
* Varianter med attributen color, size eller model grupperas automatiskt via Group sku och Group type.
* Vikt konverteras till gram utifrån butikens viktenhet.
* Momssatsen avrundas till närmsta giltiga Sello-värde (25, 12, 6, 0).
* OBS: Sello kräver .xlsx. Öppna CSV-filen i Excel och spara den som Excel-arbetsbok före import.

== Installation ==

1. Ladda upp pluginmappen till katalogen /wp-content/plugins/.
2. Aktivera pluginet via menyn "Plugins" i WordPress.
3. Kontrollera att WooCommerce är installerat och aktiverat, annars visas en varning.
4. Gå till WooCommerce → Kustom-export.
5. Valfritt: justera filtren för utkast, privata produkter och produkter ur lager.
6. Klicka på "Exportera produkter till Kustom CSV" för Kustom-filen, eller "Exportera produkter till Sello CSV" för Sello-filen.

== Användning ==

1. Gå till WooCommerce → Kustom-export.
2. Sidan visar antal enkla produkter, antal varianter och totalt antal CSV-rader som kommer att exporteras.
3. Klicka på "Exportera produkter till Kustom CSV".
4. CSV-filen laddas ner direkt till webbläsaren.

VARNING: När CSV-filen importeras till Kustom ersätts befintliga produkter i samma Kustom-butik. Granska filen innan du importerar den.

För Sello: Öppna den nedladdade CSV-filen i Excel och spara den som .xlsx. Importera sedan via Sello → Import/export → Excel. För att uppdatera befintliga produkter måste filen innehålla Sku, Id, Property EAN eller Property GTIN. Tomma celler raderar inte värden i Sello – använd Admin(delete) för att rensa ett fält. Grupptypen (color, size, model) kan inte ändras efter att gruppen skapats.

== Skärmdumpar ==

(Ännu inte tillgängliga.)

== Changelog ==

= 1.1.0 =
* Ny Sello-export med Sellos kolumnnamn, automatisk gruppering av varianter samt vikt- och momskonvertering.

= 1.0.0 =
* Första versionen. Export av enkla produkter och variabler till Kustoms produktimportformat, med exportfilter och modernt admin-gränssnitt.

== Frequently Asked Questions ==

= Vem ligger bakom pluginet? =
Pluginet är utvecklat av Linthor Webbyrå (https://linthor.com).

= Vilka kolumner finns i filen? =
Som standard endast de fyra obligatoriska: product_name, price_including_vat, vat_rate, currency, plus dynamiska attributkolumner för varianter. Valfria kolumner (sku, category, ean, image_url, stock_status) aktiveras först när formatet verifierats mot Kustoms officiella exempel-CSV-filer, eftersom Kustom tolkar extra kolumner som variantattribut.

= Hur beräknas priset? =
Priset exporteras alltid inklusive moms, baserat på produktens pris och tillämplig momssats, oavsett om butiken visar priser inklusive eller exklusive moms.
