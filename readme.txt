=== WooCommerce Kustom Product Export ===
Contributors: linthorwebbyra
Tags: woocommerce, kustom, export, csv, products
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Utvecklat av [Linthor Webbyrå](https://linthor.com).

Exportera WooCommerce-produkter till en CSV-fil redo för bulkimport i Kustom POS.

== Beskrivning ==

WooCommerce Kustom Product Export skapar en CSV-fil med alla produkter i ditt WooCommerce-catalog i exakt det format Kustom POS kräver för bulkimport av produkter.

Funktioner:

* Exporterar enkla produkter och variabla produkter (varje variant blir en egen rad).
* Fyra obligatoriska kolumner: product_name, price_including_vat, vat_rate, currency.
* Attributkolumner (exempelvis size, color) läggs till automatiskt utifrån de attribut som faktiskt används av varianterna.
* Egen attributkolumn: välj ett attribut i en dropdown; det exporteras som en extra sista kolumn och sparas som variantattribut i Kustom (exempelvis condition med värdena new eller vintage, användbart i rapporter).
* Priser exporteras alltid inklusive moms, med punkt som decimaltecken och utan tusentalsavgränsare.
* Momssats hämtas från produktens tax class, med butikens standardmomssats som fallback.
* Filnamn: kustom-products-YYYY-MM-DD-HHmm.csv, UTF-8, komma som avgränsare, LF som radslut.
* CSV-injection-skydd: cellvärden som börjar med =, +, - eller @ prefixas med en apostrof.
* Låg minnesanvändning: produkter hämtas och skrivs i omgångar om 100.

== Installation ==

1. Ladda upp pluginmappen till katalogen /wp-content/plugins/.
2. Aktivera pluginet via menyn "Plugins" i WordPress.
3. Kontrollera att WooCommerce är installerat och aktiverat, annars visas en varning.
4. Gå till WooCommerce → Kustom-export.
5. Valfritt: justera filtren för utkast, privata produkter och produkter ur lager.
6. Valfritt: välj ett eget attribut i dropdownen "Egen attributkolumn" om du vill exportera ett extra attribut som sista kolumn.
7. Klicka på "Exportera produkter till Kustom CSV" för att ladda ner filen.

== Användning ==

1. Gå till WooCommerce → Kustom-export.
2. Sidan visar antal enkla produkter, antal varianter och totalt antal CSV-rader som kommer att exporteras.
3. Valfritt: välj ett attribut i dropdownen "Egen attributkolumn". Listan hämtas automatiskt från de attribut som finns i butikens produktkatalog.
4. Klicka på "Exportera produkter till Kustom CSV".
5. CSV-filen laddas ner direkt till webbläsaren.

VARNING: När CSV-filen importeras till Kustom ersätts befintliga produkter i samma Kustom-butik. Granska filen innan du importerar den.

== Skärmdumpar ==

(Ännu inte tillgängliga.)

== Changelog ==

= 1.0.0 =
* Första versionen. Export av enkla produkter och variabler till Kustoms produktimportformat, med exportfilter och modernt admin-gränssnitt.

== Frequently Asked Questions ==

= Vem ligger bakom pluginet? =
Pluginet är utvecklat av Linthor Webbyrå (https://linthor.com).

= Vilka kolumner finns i filen? =
Som standard endast de fyra obligatoriska: product_name, price_including_vat, vat_rate, currency, plus dynamiska attributkolumner för varianter. Valfria kolumner (sku, category, ean, image_url, stock_status) aktiveras först när formatet verifierats mot Kustoms officiella exempel-CSV-filer, eftersom Kustom tolkar extra kolumner som variantattribut.

= Hur beräknas priset? =
Priset exporteras alltid inklusive moms, baserat på produktens pris och tillämplig momssats, oavsett om butiken visar priser inklusive eller exklusive moms.
