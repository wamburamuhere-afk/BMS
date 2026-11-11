<?php
/**
 * core/product_copy.php — what each product really delivers, in English and Kiswahili.
 *
 * ONE source for the public pages that describe the products: the sign-up page (register.php)
 * and the landing page (landing.php). Moved here unchanged from register.php (2026-11-11).
 * Verified against core/plans.php platformProductProfile + core/feature_registry.php (2026-10-23):
 * do not add a benefit the product does not deliver.
 *
 * Product names, plans, prices, limits and terms are NOT here — they come live from Superadmin
 * (listPublicOffers()). This is only the wording around them, keyed by product_key.
 */

if (!function_exists('bmsProductCopy')) {
    function bmsProductCopy(bool $sw): array
    {
        return [
            'bms_shop' => [
                'icon'     => 'pos',
                'tag'      => $sw ? 'Bora kwa maduka' : 'Best for shops',
                'desc'     => $sw ? 'Mauzo (POS), stock na usimamizi rahisi wa duka.' : 'Point of sale, stock and simple shop management.',
                'head'     => $sw ? 'Simamia duka lako kwa njia ya kisasa.' : 'Manage your shop the smart way.',
                'sub'      => $sw ? 'Uza haraka, jua kilichopo stoo, fuatilia wanaokudai na uone faida yako — kwa simu au kompyuta.'
                                  : 'Sell fast, know what is in stock, track who owes you and see your profit — from your phone or computer.',
                'benefits' => $sw
                    ? ['Kuuza haraka, na risiti ya kuchapisha au ya email', 'Bidhaa, huduma na kupokea stock', 'Mauzo ya mkopo — fuatilia wanaokudai', 'Matumizi na faida', 'Ripoti: kila siku, wiki, mwezi, robo mwaka na mwaka', 'Kiingereza na Kiswahili, kwenye simu na kompyuta']
                    : ['Fast selling with printed or emailed receipts', 'Products, services and receiving stock', 'Credit sales — track who owes you', 'Expenses and profit tracking', 'Reports: daily, weekly, monthly, quarterly and yearly', 'English and Swahili, on phone and computer'],
                'card'     => $sw
                    ? ['Kuuza haraka na risiti', 'Bidhaa na kupokea stock', 'Mauzo ya mkopo — wanaokudai', 'Ripoti za siku hadi mwaka']
                    : ['Fast selling with receipts', 'Products and stock receiving', 'Credit sales — who owes you', 'Reports from daily to yearly'],
                'step'     => $sw ? 'Duka' : 'Shop',
                'co_h'     => $sw ? 'Tueleze kuhusu duka lako' : 'Tell us about your shop',
                'co_sub'   => $sw ? 'Hii itaonekana kwenye risiti zako. Unaweza kuibadilisha baadaye kwenye Settings.' : 'This appears on your receipts. You can change it later in Settings.',
                'name_l'   => $sw ? 'Jina la biashara / duka' : 'Business / shop name',
                'type_l'   => $sw ? 'Aina ya duka' : 'Type of shop',
                'type_ph'  => $sw ? 'mf. Hardware, Famasi, Boutique' : 'e.g. Hardware, Pharmacy, Boutique',
                'logo_for' => $sw ? 'inaonekana kwenye risiti zako' : 'shows on your receipts',
                'admin_sub'=> $sw ? 'Utaingia kwa username na password yako.' : 'You will sign in with your username and password.',
                'first'    => $sw
                    ? ['Ongeza bidhaa na bei zake', 'Pokea stock ya mwanzo', 'Fanya mauzo ya kwanza', 'Angalia mauzo ya leo kwenye dashboard']
                    : ['Add your products and prices', 'Receive your opening stock', 'Make your first sale', "Check today's sales on your dashboard"],
                'demo_t'   => $sw ? 'Mauzo ya leo' : "Today's sales",
                'demo_v'   => ['845,000', '1,120,000', '1,365,000'],
                'demo_n'   => $sw ? ['Mauzo mapya · 35,000 TZS', 'Deni limelipwa · 20,000 TZS', 'Mauzo mapya · 12,500 TZS']
                                  : ['New sale · 35,000 TZS', 'Credit repaid · 20,000 TZS', 'New sale · 12,500 TZS'],
            ],
            'bms_full' => [
                'icon'     => 'bms',
                'tag'      => $sw ? 'Mfumo kamili' : 'Complete system',
                'desc'     => $sw ? 'Mfumo kamili wa usimamizi wa biashara.' : 'The complete business management system.',
                'head'     => $sw ? 'Endesha biashara yako yote kwenye mfumo mmoja.' : 'Run your whole business in one system.',
                'sub'      => $sw ? 'Mauzo, manunuzi, fedha, HR na miradi kwenye mfumo mmoja uliounganishwa — hesabu zako zinakubaliana kila wakati.'
                                  : 'Sales, purchases, finance, HR and projects in one connected system — so your numbers always agree.',
                'benefits' => $sw
                    ? ['Mauzo, nukuu (quotations) na ankara', 'Manunuzi, wasambazaji na maghala', 'Fedha: matumizi, benki, journals na ripoti za fedha', 'HR na mishahara (payroll)', 'Miradi, mali (assets) na CRM', 'Point of Sale imejumuishwa']
                    : ['Sales, quotations and invoicing', 'Purchases, suppliers and warehouses', 'Finance: expenses, banks, journals and financial reports', 'HR and payroll', 'Projects, assets and CRM', 'Point of Sale included'],
                'card'     => $sw
                    ? ['Mauzo, nukuu na ankara', 'Manunuzi na maghala', 'Fedha na ripoti za fedha', 'HR, mishahara, miradi na CRM']
                    : ['Sales, quotations and invoicing', 'Purchases and warehouses', 'Finance and financial reports', 'HR, payroll, projects and CRM'],
                'step'     => $sw ? 'Kampuni' : 'Company',
                'co_h'     => $sw ? 'Tueleze kuhusu kampuni yako' : 'Tell us about your company',
                'co_sub'   => $sw ? 'Hii itaonekana kwenye ankara na ripoti zako. Unaweza kuibadilisha baadaye kwenye Settings.' : 'This appears on your invoices and reports. You can change it later in Settings.',
                'name_l'   => $sw ? 'Jina la kampuni' : 'Company name',
                'type_l'   => $sw ? 'Aina ya biashara' : 'Business type',
                'type_ph'  => $sw ? 'mf. Ujenzi, Biashara, Usafirishaji' : 'e.g. Construction, Trading, Logistics',
                'logo_for' => $sw ? 'inaonekana kwenye ankara na ripoti' : 'shows on your invoices and reports',
                'admin_sub'=> $sw ? 'Utaingia kwa username na password yako. Ongeza wafanyakazi na majukumu yao baada ya kuingia.'
                                  : 'You will sign in with your username and password. Add your staff and their roles after sign-in.',
                'first'    => $sw
                    ? ['Pitia chart of accounts yako', 'Ongeza wateja na wasambazaji', 'Tengeneza ankara ya kwanza', 'Ongeza wafanyakazi na majukumu yao']
                    : ['Review your chart of accounts', 'Add customers and suppliers', 'Create your first invoice', 'Add your staff and their roles'],
                'demo_t'   => $sw ? 'Mapato ya mwezi huu' : 'Revenue this month',
                'demo_v'   => ['12,400,000', '13,150,000', '14,620,000'],
                'demo_n'   => $sw ? ['Ankara INV-0042 imelipwa', 'Oda ya manunuzi imeidhinishwa', 'Mishahara imerekodiwa']
                                  : ['Invoice INV-0042 paid', 'Purchase order approved', 'Payroll posted'],
            ],
        ];
    }
}

if (!function_exists('bmsRestaurantCopy')) {
    /**
     * Smart POS wording for a RESTAURANT (2026-11-11): the same product and plans as bms_shop, but a
     * restaurant owner reads only about restaurants. register.php lays this over the bms_shop wording
     * when "Restaurant" is chosen; the landing page's Restaurant page uses it too. Same keys as
     * bmsProductCopy()['bms_shop'] (tests/test_landing_cli.php checks).
     * Verified 2026-11-11: tables (restaurant_tables), kitchen screen (restaurant_kitchen_mode),
     * menu photos + recipes, QR menu ordering (/m/<link>), supplier bills paid later, food waste,
     * ready roles Waiter / Cashier / Cook / Restaurant Manager (rofsEnsureReadyRoles at sign-up).
     */
    function bmsRestaurantCopy(bool $sw): array
    {
        return [
            'icon'     => 'pos',
            'tag'      => $sw ? 'Bora kwa migahawa' : 'Best for restaurants',
            'desc'     => $sw ? 'Meza, jikoni, menyu na malipo kwa mgahawa wako.' : 'Tables, kitchen, menu and payments for your restaurant.',
            'head'     => $sw ? 'Kutoka mezani, hadi jikoni, hadi bili.' : 'From the table to the kitchen to the bill.',
            'sub'      => $sw ? 'Mhudumu anachukua oda, jikoni wanaiona kwenye skrini, na keshia anafunga bili. Kila mmoja anaona kazi yake tu.'
                              : 'Waiters take orders, the kitchen sees them on screen, and the cashier closes the bill. Each person sees only their own work.',
            'benefits' => $sw
                ? ['Meza na oda, zinatumwa jikoni', 'Skrini ya jikoni kwa wapishi', 'Menyu yenye picha na mapishi', 'Menyu kwa QR: mteja anaagiza akiwa mezani', 'Nunua kwa mkopo kwa wasambazaji, lipa baadaye', 'Upotevu wa chakula, matumizi na faida']
                : ['Tables and orders, sent to the kitchen', 'Kitchen screen for your cooks', 'Menu with photos and recipes', 'QR menu: guests order from the table', 'Buy from suppliers on credit, pay later', 'Food waste, expenses and profit'],
            'card'     => $sw
                ? ['Meza na oda', 'Skrini ya jikoni', 'Menyu, mapishi na QR', 'Ripoti za siku hadi mwaka']
                : ['Tables and orders', 'Kitchen screen', 'Menu, recipes and QR ordering', 'Reports from daily to yearly'],
            'step'     => $sw ? 'Mgahawa' : 'Restaurant',
            'co_h'     => $sw ? 'Tueleze kuhusu mgahawa wako' : 'Tell us about your restaurant',
            'co_sub'   => $sw ? 'Hii itaonekana kwenye bili na risiti zako. Unaweza kuibadilisha baadaye kwenye Settings.' : 'This appears on your bills and receipts. You can change it later in Settings.',
            'name_l'   => $sw ? 'Jina la mgahawa' : 'Restaurant name',
            'type_l'   => $sw ? 'Aina ya mgahawa' : 'Type of restaurant',
            'type_ph'  => $sw ? 'mf. Mgahawa, Café, Grill, Jiko la hoteli' : 'e.g. Restaurant, Café, Grill, Hotel kitchen',
            'logo_for' => $sw ? 'inaonekana kwenye bili na risiti zako' : 'shows on your bills and receipts',
            'admin_sub'=> $sw ? 'Utaingia kwa username na password yako. Kisha ongeza wafanyakazi na uwape majukumu: Mhudumu, Keshia au Mpishi.'
                              : 'You will sign in with your username and password. Then add your staff and give each a role: Waiter, Cashier or Cook.',
            'first'    => $sw
                ? ['Ongeza vyakula na bei zake kwenye menyu', 'Weka meza zako', 'Ongeza wafanyakazi: mhudumu, keshia, mpishi', 'Pokea oda ya kwanza']
                : ['Add your dishes and prices to the menu', 'Set up your tables', 'Add your staff: waiter, cashier, cook', 'Take your first order'],
            'demo_t'   => $sw ? 'Mauzo ya leo' : "Today's sales",
            'demo_v'   => ['412,000', '688,500', '903,000'],
            'demo_n'   => $sw ? ['Meza 4 · bili imelipwa · 42,000 TZS', 'Oda mpya jikoni · Meza 7', 'Oda ya QR imekubaliwa · Meza 2']
                              : ['Table 4 · bill paid · 42,000 TZS', 'New kitchen order · Table 7', 'QR order accepted · Table 2'],
        ];
    }
}
