<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * Registro tipizzato delle manopole del titolare (docs/02 § Registro delle impostazioni).
 * Aggiungere una manopola = una voce qui (tipo, default, etichetta, validazione) + un test.
 * La pagina /admin/impostazioni è generata da questo elenco.
 */
final class Registry
{
    /** Gruppi nell'ordine in cui compaiono in /admin/impostazioni. */
    public const GROUPS = [
        'orders' => 'Ordini',
        'supplier' => 'Ordini al fornitore',
        'catalog' => 'Catalogo',
        'prices' => 'Prezzi',
        'tax' => 'IVA',
        'shipping' => 'Spedizione',
        'images' => 'Immagini',
        'company' => 'Azienda e contatti',
    ];

    /** Corrieri ammessi nei link di tracking mostrati ai clienti (docs/06). */
    public const DEFAULT_CARRIER_DOMAINS = [
        'brt.it', 'gls-italy.com', 'gls-group.eu', 'gls-group.com', 'dhl.com', 'dhl.it', 'dhl.de',
        'ups.com', 'fedex.com', 'tnt.com', 'tnt.it', 'poste.it', 'sda.it', 'dpd.com', 'dpd.it',
        'dpd.de', 'inpost.it', 'inpost.eu', 'postnl.nl', 'laposte.fr', 'colissimo.fr', 'chronopost.fr',
        'correos.es', 'seur.com', 'mrw.es', 'nacex.es', 'royalmail.com', 'parcelforce.com', 'post.ch',
        'post.at', 'bpost.be', 'posti.fi', 'postnord.com', 'an-post.ie', 'anpost.com', 'hermesworld.com',
        'myhermes.de', 'evri.com',
    ];

    /** Note di default per il fornitore (D-020): spedizione a nome nostro, nessun contatto col destinatario. */
    public const DEFAULT_SUPPLIER_NOTES = 'Dropship white-label: mittente su pacco ed etichetta SHOES & CLOTHING RESELLING OFFICIAL STORE. '
        . 'Nessun prezzo, documento o riferimento al vostro marchio nel pacco. Non contattate il destinatario (email, SMS, telefono).';

    /** @var array<string, Definition> */
    private array $definitions = [];

    public function __construct()
    {
        foreach ($this->build() as $definition) {
            $this->definitions[$definition->key] = $definition;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    public function get(string $key): Definition
    {
        return $this->definitions[$key] ?? throw new \InvalidArgumentException('Impostazione sconosciuta: ' . $key);
    }

    /** @return array<string, Definition> */
    public function all(): array
    {
        return $this->definitions;
    }

    /** @return array<string, list<Definition>> gruppo => voci */
    public function grouped(): array
    {
        $out = [];
        foreach (array_keys(self::GROUPS) as $group) {
            $out[$group] = [];
        }
        foreach ($this->definitions as $definition) {
            $out[$definition->group][] = $definition;
        }

        return array_filter($out);
    }

    /** @return list<Definition> */
    private function build(): array
    {
        $email = static function (mixed $value): ?string {
            foreach ((array) $value as $item) {
                if (filter_var($item, FILTER_VALIDATE_EMAIL) === false) {
                    return 'Indirizzo email non valido: ' . $item;
                }
            }

            return null;
        };
        $domains = static function (mixed $value): ?string {
            foreach ((array) $value as $item) {
                if (preg_match('/^(?=.{3,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', (string) $item) !== 1) {
                    return 'Dominio non valido: ' . $item;
                }
            }

            return null;
        };
        $url = static fn (mixed $value): ?string => $value === '' || preg_match('#^https://[^\s<>"]+$#', (string) $value) === 1
            ? null : 'Serve un indirizzo che inizia con https://';

        return [
            // Ordini
            new Definition('orders.min_items', 'int', 5, 'Ordine minimo (paia)', 'orders',
                'Si usa il maggiore tra questo valore e l\'eventuale minimo del fornitore (profilo Wholesaler).', min: 1, max: 1000),
            new Definition('orders.rethink_seconds', 'int', 15, 'Secondi per ripensarci prima dell\'invio', 'orders',
                'Conto alla rovescia con "Annulla" prima che la richiesta d\'ordine parta davvero. 0 = invio diretto.', min: 0, max: 120),

            // Ordini al fornitore (D-021: spostate dal .env, prima WTB_ORDERS_ENABLED, WTB_ORDER_TRIGGER, …)
            new Definition('supplier.orders_enabled', 'bool', true, 'Invia gli ordini al fornitore', 'supplier',
                'Freno d\'emergenza: spento, nessun ordine parte verso il fornitore, nemmeno col bottone nella pagina dell\'ordine.'),
            new Definition('supplier.order_trigger', 'enum', 'on_confirm', 'Quando inviare l\'ordine al fornitore', 'supplier',
                'Con "Subito" le paia si bloccano prima, ma se il cliente poi non paga l\'ordine al fornitore va annullato contattando il fornitore (D-009).',
                options: [
                    'on_confirm' => 'Quando confermi che il cliente ha pagato (consigliato)',
                    'on_request' => 'Subito, appena il cliente invia l\'ordine',
                    'manual' => 'Solo quando premi il bottone nella pagina dell\'ordine',
                ]),
            new Definition('supplier.payment_method', 'enum', 'bank_transfer', 'Come paghi il fornitore', 'supplier',
                'Vale per i nuovi ordini al fornitore; quelli già fatti restano come sono (D-004).',
                options: [
                    'bank_transfer' => 'Bonifico: per ogni ordine ricevi IBAN, causale e importo',
                    'balance' => 'Credito prepagato: scalato subito alla creazione dell\'ordine',
                ]),
            new Definition('supplier.max_order', 'money', 0, 'Spesa massima per ordine al fornitore (€)', 'supplier',
                'Se il costo di un ordine supera questa cifra, l\'ordine non parte e ricevi un avviso. 0 = nessun limite.', min: 0),
            new Definition('supplier.ship_to_email', 'enum', 'admin', 'Email per la consegna data al fornitore', 'supplier',
                'Il fornitore e il corriere usano questa email per la consegna (D-020).',
                options: [
                    'admin' => 'La tua: il fornitore non scrive mai al tuo cliente (consigliato)',
                    'customer' => 'Quella del destinatario del pacco',
                ]),
            new Definition('supplier.ship_to_phone', 'enum', 'admin', 'Telefono per la consegna dato al fornitore', 'supplier',
                'Il corriere chiama questo numero se ha problemi con la consegna (D-020).',
                options: [
                    'admin' => 'Il tuo (telefono in Azienda e contatti): il corriere chiama te',
                    'customer' => 'Quello del destinatario: il corriere chiama lui',
                    'none' => 'Nessun telefono',
                ]),
            new Definition('supplier.notes', 'text', self::DEFAULT_SUPPLIER_NOTES, 'Nota inviata al fornitore con ogni ordine', 'supplier',
                'Serve a chiedere di spedire a nome tuo e di non contattare il destinatario: il fornitore non ha un campo apposta (D-020). Le note dei clienti non vanno mai al fornitore. Vuoto = nessuna nota.',
                validator: static fn (mixed $v): ?string => mb_strlen((string) $v) <= 500 ? null : 'Al massimo 500 caratteri.'),
            new Definition('supplier.unpaid_days', 'int', 7, 'Avvisami se un ordine al fornitore non è pagato dopo (giorni)', 'supplier',
                'Compare un avviso nella dashboard (D-020). 0 = nessun avviso.', min: 0, max: 60),

            // Catalogo
            new Definition('catalog.supplier_all', 'bool', false, 'Prendi tutto il catalogo del fornitore', 'catalog',
                'Spento: solo i prodotti che hai scelto nel portale del fornitore. Acceso: tutti i suoi prodotti. Vale dal prossimo aggiornamento del catalogo (D-021).'),
            new Definition('catalog.sell_extended', 'bool', true, 'Vendi anche lo stock esteso', 'catalog',
                'Paia in un magazzino esterno, partenza in 3-5 giorni lavorativi, prezzo diverso (D-005). Spento: solo pronta consegna.'),
            new Definition('catalog.regimes', 'enum', 'margin,ordinary', 'Regimi IVA in vendita', 'catalog',
                'Le offerte del regime spento spariscono da catalogo, carrello ed export (docs/04).',
                options: ['margin,ordinary' => 'Margine e ordinario', 'margin' => 'Solo margine', 'ordinary' => 'Solo ordinario']),
            new Definition('catalog.per_page', 'int', 24, 'Prodotti per pagina', 'catalog', '', min: 6, max: 96),
            new Definition('catalog.availability_high_min', 'int', 60, 'Disponibilità "alta" da (paia)', 'catalog',
                'Soglia della pillola verde sulle card.', min: 1, max: 100000),
            new Definition('catalog.availability_low_max', 'int', 20, 'Disponibilità "bassa" fino a (paia)', 'catalog',
                'Soglia della pillola arancione; tra le due soglie la disponibilità è "media".', min: 0, max: 100000),
            new Definition('catalog.sold_out_days', 'int', 30, 'Giorni di visibilità degli esauriti', 'catalog',
                'Un prodotto sparito dal feed resta visibile come esaurito per questi giorni, poi esce dal catalogo (D-017). 0 = esce subito.', min: 0, max: 365),

            // Prezzi
            new Definition('prices.rounding', 'enum', 'whole', 'Arrotondamento del prezzo di vendita', 'prices',
                'Sempre per eccesso, così il margine non scende mai sotto la regola.',
                options: ['whole' => 'All\'euro intero', 'half' => 'Ai 50 centesimi', 'none' => 'Al centesimo']),
            new Definition('prices.default_margin_type', 'enum', 'percent', 'Tipo di margine di default', 'prices',
                'Vale per le offerte che nessuna regola di /admin/margini prende.',
                options: ['percent' => 'Percentuale sul costo', 'fixed' => 'Importo fisso in euro']),
            new Definition('prices.default_margin_value', 'decimal', '5.00', 'Margine di default', 'prices',
                'Percentuale (es. 5 = 5%) o euro (es. 10,00) secondo il tipo scelto sopra.'),

            // IVA
            new Definition('tax.vat_rate_it', 'int', 22, 'Aliquota IVA per i clienti italiani (%)', 'tax',
                'Si applica alle righe in regime ordinario e, se ordinaria, alla spedizione (docs/04).', min: 0, max: 30),
            new Definition('tax.shipping_regime', 'enum', 'ordinary', 'Trattamento IVA della spedizione', 'tax',
                'Proposta tecnica da validare col commercialista (D-007).',
                options: ['ordinary' => 'Ordinaria: netta + IVA secondo il paese', 'final' => 'Importo finale, IVA non esposta']),

            // Spedizione
            new Definition('shipping.carrier_domains', 'list', self::DEFAULT_CARRIER_DOMAINS, 'Domini dei corrieri ammessi nei link di tracking', 'shipping',
                'Il link di tracking compare al cliente solo se punta a uno di questi domini (o a un loro sottodominio). Uno per riga.',
                validator: $domains),

            // Immagini
            new Definition('images.proxy', 'bool', true, 'Immagini servite dal nostro dominio', 'images',
                'Spento: link diretti all\'host del fornitore (sconsigliato, D-011).'),

            // Azienda e contatti (mostrati ai clienti; da confermare col titolare, docs/01)
            new Definition('company.site_name', 'string', 'SCS B2B', 'Nome della piattaforma mostrato ai clienti', 'company',
                'Compare in intestazione, email e PDF. Mai il nome del fornitore.'),
            new Definition('company.name', 'string', 'SHOES & CLOTHING RESELLING OFFICIAL STORE', 'Ragione sociale', 'company'),
            new Definition('company.vat', 'string', '091460600729', 'Partita IVA', 'company'),
            new Definition('company.address', 'string', 'Via Paolo Ricci 12/a, 76121 Barletta (BT), Italia', 'Sede', 'company'),
            new Definition('company.email', 'string', 'shoes.clothing_reselling@yahoo.com', 'Email pubblica', 'company', '',
                validator: static fn (mixed $v): ?string => filter_var($v, FILTER_VALIDATE_EMAIL) === false ? 'Indirizzo email non valido.' : null),
            new Definition('company.phone', 'string', '+39 392 772 0691', 'Telefono', 'company'),
            new Definition('company.whatsapp', 'string', '393927720691', 'Numero WhatsApp (solo cifre, con prefisso)', 'company',
                'Usato per il link https://wa.me/<numero>. Vuoto = niente bottone WhatsApp.',
                validator: static fn (mixed $v): ?string => $v === '' || preg_match('/^\d{6,15}$/', (string) $v) === 1 ? null : 'Solo cifre, es. 393927720691.'),
            new Definition('company.website', 'string', 'https://shoesclothingstore.com/', 'Sito principale', 'company', '', validator: $url),
            new Definition('company.admin_emails', 'list', ['info@shoesclothingstore.com'], 'Email che ricevono ordini e avvisi', 'company',
                'Non mostrate ai clienti. Una per riga.', validator: $email),
        ];
    }
}
