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

            // Catalogo
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
