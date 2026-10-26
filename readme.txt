=== WooCommerce Product Export (Kustom & Sello) ===
Contributors: linthorwebbyra
Tags: woocommerce, kustom, sello, export, csv, excel, products
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Utvecklat av [Linthor Webbyrå](https://linthor.com).

Exportera WooCommerce-produkter till CSV- och Excel-filer redo för bulkimport i Kustom POS och Sello.

== Beskrivning ==

WooCommerce Product Export (Kustom & Sello) skapar CSV- och Excel-filer med alla produkter i din WooCommerce-katalog i exakt det format Kustom POS och Sello kräver för bulkimport av produkter.

Funktioner:

* Ny och intuitiv Dashboard: Tydlig översikt med snabbkort för Kustom POS och Sello.
* Dedikerade exportsidor för Kustom POS och Sello med fliknavigering.
* Tre exportformat: Kustom POS CSV, Sello Excel (.xlsx) och Sello CSV.
* Exporterar enkla produkter och variabla produkter (varje variant blir en egen rad).
* Kustom: fyra obligatoriska kolumner (product_name, price_including_vat, vat_rate, currency) plus valfria WooCommerce-fält och dynamiska attributkolumner.
* Kustom fältväljare: Välj fritt bland alla standardfält i WooCommerce (SKU, Produkt-ID, Föräldra-SKU, Produkttyp, Ordinarie pris, Reapris, Pris exkl. moms, Momsklass, Lagersaldo, Lagerstatus, Lagerhantering, EAN/Streckkod, Beskrivning, Kort beskrivning, Kategorier, Taggar, Vikt, Längd, Bredd, Höjd, Fraktklass, Bild-URL, Galleribilder).
* Kustom flerval av attribut: Välj flera eller alla registrerade produktattribut som egna kolumner med en-och-en kryssrutor samt "Markera alla"-knappar.
* Sello: kolumner enligt Sellos officiella Excel-importdokumentation (Sku, Standard name, Standard description, Price, VAT rate, Property EAN, Property GTIN, Images, Group sku, Group type, Stock). Variationer grupperas via Group sku och Group type (color, model eller size).
* Priser: Kustom exporterar priser inklusive moms, medan Sello exporterar priser exklusive moms tillsammans med momssats enligt marknadsplatsstandard.
* Filnamn: kustom-products-YYYY-MM-DD-HHmm.csv respektive sello-products-YYYY-MM-DD-HHmm.csv/xlsx, UTF-8, komma som avgränsare, LF som radslut.
* CSV-injection-skydd: cellvärden som börjar med =, +, - eller @ prefixas med en apostrof.
* Låg minnesanvändning: produkter strömmas rad för rad även för stora kataloger.

== Installation ==

1. Ladda upp pluginmappen till katalogen /wp-content/plugins/.
2. Aktivera pluginet via menyn "Plugins" i WordPress.
3. Kontrollera att WooCommerce är installerat och aktiverat, annars visas en varning.
4. Gå till WooCommerce → Produkt-export.
5. Välj Kustom POS eller Sello via dashboardkorten eller flikarna högst upp.
6. Ladda ner exportfilen direkt till webbläsaren.

== Användning ==

1. Gå till WooCommerce → Produkt-export.
2. Översikten visar antal enkla produkter, antal varianter och totalt antal rader i butiken, samt två tydliga kort för Kustom POS och Sello.
3. Klicka på "Öppna Kustom-export" för att hantera Kustom-specifika inställningar (som valfria WooCommerce-kolumner och attribut) och ladda ner Kustom POS CSV.
4. Klicka på "Öppna Sello-export" för att ladda ner Sello Excel (.xlsx) eller CSV och följa steg-för-steg-guiden för Sello-import.

Kustom: importera filen via Kustom POS produktimport. VARNING: befintliga produkter i samma Kustom-butik ersätts vid import. Granska filen innan du importerar den.

Sello: logga in i Sello, öppna menyn uppe till vänster, gå till Import / export, välj Excel i Import-sektionen och välj "Skapa nya produkter från min fil" eller "Uppdatera produkter med min fil".

== Changelog ==

= 1.4.0 =
* Stöd för alla WooCommerce-fält i Kustom POS CSV-export: SKU, Produkt-ID, Föräldra-SKU, Produkttyp, Ordinarie pris, Reapris, Pris exkl. moms, Momsklass, Lagersaldo, Lagerstatus, Lagerhantering, EAN/Streckkod, Beskrivning, Kort beskrivning, Kategorier, Taggar, Vikt, Mått (Längd, Bredd, Höjd), Fraktklass, Bild-URL och Galleribilder.
* Flerval av produktattribut för Kustom-export: Nu kan administratören kryssa i flera eller alla produktattribut som ska exporteras som egna kolumner.
* Nya interaktiva snabbvalsknappar: "Markera alla" och "Avmarkera alla" för både standardfält och attribut, med fullt stöd för att bocka i och ur fälten en och en.
* Ny modern sticky kolumnöversikt som visar alla aktiva kolumner i CSV-filen med tydliga taggar för basfält (Krav), valda fält och attribut.

= 1.3.0 =
* Ny och extremt användarvänlig informationsarkitektur: Pluginet har nu en central Dashboard (Översikt) med snabbkort för Kustom POS och Sello samt dedikerade undersidor för vardera plattform.
* Ny modern fliknavigering (Översikt, Kustom POS, Sello) och brödsmulor.
* Uppdaterat namn till WooCommerce Product Export (Kustom & Sello) och menynamn till "Produkt-export" under WooCommerce.
* Ny steg-för-steg importguide och formatinformation för Sello på den dedikerade Sello-sidan.
* Förbättrad tillgänglighet (WCAG 2.1 AA) och responsivitet för alla skärmstorlekar.

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
