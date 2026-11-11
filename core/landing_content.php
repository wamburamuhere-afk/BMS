<?php
/**
 * core/landing_content.php — data, wording and search-engine details for the public website (landing.php).
 *
 * landingData()   live: products/plans/prices/terms/limits (listPublicOffers — Superadmin › Plans),
 *                 free-trial days, whether sign-up is open, the company-address domain, FAQ, segments
 * landingCopy()   the page wording, English and Kiswahili. Every statement describes something the
 *                 system really does (checked 2026-11-11); numbers on the product preview are labelled "Example".
 * landingMeta()   <title> + description per page
 * landingJsonLd() structured data (Organization, WebSite, SoftwareApplication with live prices, FAQPage)
 *
 * Plans are shown exactly as the sign-up page shows them (same feature lines, same term labels).
 */

require_once __DIR__ . '/plans.php';
require_once __DIR__ . '/tenant_lifecycle_policy.php';
require_once __DIR__ . '/tenant_resolver.php';
require_once __DIR__ . '/product_copy.php';

if (!function_exists('landingCopy')) {
    function landingCopy(bool $sw, int $trialDays): array
    {
        $days = max(0, $trialDays);
        return $sw ? [
            'home' => 'Mwanzo', 'menu' => 'Menyu', 'products' => 'Bidhaa', 'login' => 'Ingia', 'trial' => 'Anza bure', 'contact' => 'Wasiliana',
            'trial_long' => 'Anza majaribio ya bure', 'see_how' => 'Angalia Smart POS', 'see_prices' => 'Ona bei', 'learn_more' => 'Soma zaidi',
            'badge' => 'Smart POS · Smart BMS · Kiingereza na Kiswahili',
            'hero_title' => 'Biashara yako yote, kwenye skrini moja.',
            'hero_sub' => 'Mauzo, stock, madeni, matumizi na faida — kwa simu au kompyuta, kwa Kiswahili au Kiingereza.',
            'points' => $days > 0 ? [sprintf('Siku %d bure', $days), 'Hakuna malipo leo', 'Akaunti yako binafsi'] : ['Hakuna malipo leo', 'Akaunti yako binafsi', 'Simu na kompyuta'],
            'example' => 'Mfano', 'today' => 'Leo', 'app_menu' => ['Dashibodi', 'Uza', 'Bidhaa', 'Wanaodaiwa', 'Ripoti'],
            'k_sales' => 'Mauzo ya leo', 'k_profit' => 'Faida ghafi', 'k_credit' => 'Madeni yaliyolipwa',
            'built_title' => 'Imejengwa kwa jinsi unavyofanya kazi',
            'values' => [
                ['Rahisi kwa mmiliki', 'Imetengenezwa kwa wamiliki wa biashara, si wahasibu tu — kwa Kiswahili au Kiingereza.'],
                ['Kila jukumu, kazi yake', 'Wafanyakazi wanaona wanachohitaji tu. Mfano, mhudumu wa meza haoni pesa.'],
                ['Taarifa zako ni zako', 'Kila kampuni ina database yake binafsi, na nakala inahifadhiwa kila siku.'],
            ],
            'band_title' => 'Uko tayari kuanza?', 'band_sub' => $days > 0 ? sprintf('Siku %d bure — hakuna malipo leo.', $days) : 'Hakuna malipo leo.',
            'band_sub_closed' => 'Ongea nasi tukufungulie akaunti.',
            'start_with' => 'Anza na', 'ask_price' => 'Uliza bei', 'bms_approval' => 'Akaunti za Smart BMS zinakaguliwa na BJP kabla ya kufunguliwa.',
            'what_you_get' => 'Unachopata', 'first_steps' => 'Hatua zako za kwanza',
            'rest_kicker' => 'Mgahawa', 'rest_title' => 'Smart POS kwa migahawa', 'rest_sub' => 'Chagua "Mgahawa" unapojisajili: duka lako la kwanza linafunguka katika hali ya Mgahawa. Mhudumu, keshia na mpishi — kila mmoja anaona kazi yake tu.',
            'rest_list' => ['Meza na oda', 'Skrini ya jikoni', 'Menyu na mapishi', 'Gharama kwa sahani', 'Menyu kwa QR', 'Ripoti ya upotevu'],
            'price_title' => 'Bei wazi. Hakuna mshangao.', 'price_sub' => $days > 0 ? sprintf('Kila mpango unaanza na siku %d bure. Badilisha mpango wakati wowote.', $days) : 'Badilisha mpango wakati wowote.',
            'no_prices' => 'Bei zinaandaliwa', 'no_prices_sub' => 'Ongea nasi tukueleze bei.',
            'billing' => 'Muda wa malipo', 'popular' => 'Maarufu zaidi', 'per_month' => 'kwa mwezi', 'not_term' => 'Haupo kwa muda huu',
            'after_trial' => $days > 0 ? sprintf('baada ya siku %d bure', $days) : 'kwa kipindi', 'compare' => 'Linganisha mipango', 'feature' => 'Kipengele',
            'faq_title' => 'Maswali yanayoulizwa', 'faq_sub' => 'Chagua mada upande wa kushoto.', 'topics' => 'Mada',
            'still_q' => 'Bado una swali?', 'still_q_sub' => 'Tuko tayari kukusaidia.',
            'contact_title' => 'Ongea nasi', 'contact_sub' => 'Tunakusaidia kuchagua na kuanza.', 'phone' => 'Simu', 'hrs' => 'Saa', 'hours' => 'Saa za kazi',
            'form_title' => 'Tutumie ujumbe', 'form_sub_wa' => 'Ujumbe wako utafunguka kwenye WhatsApp, tayari kutumwa.', 'form_sub_mail' => 'Ujumbe wako utafunguka kwenye email yako, tayari kutumwa.',
            'f_name' => 'Jina lako', 'f_biz' => 'Aina ya biashara', 'biz_opts' => ['Duka', 'Mgahawa', 'Kampuni', 'Nyingine'], 'f_msg' => 'Ujumbe',
            'send_wa' => 'Tuma kwa WhatsApp', 'send_mail' => 'Tuma kwa email', 'no_contacts' => 'Mawasiliano yataonekana hapa hivi karibuni.', 'call_us' => 'Tupigie simu.',
            'foot_about' => 'Mfumo wa mauzo na usimamizi wa biashara kwa maduka, migahawa na kampuni.',
            'privacy' => 'Sera ya Faragha', 'staff_login' => 'Wafanyakazi wa BJP: ingia hapa', 'close' => 'Funga',
            'co_addr' => 'Anwani ya kampuni yako', 'co_ph' => 'jina-la-kampuni', 'co_find' => 'Tafuta kampuni yangu', 'co_go' => 'Endelea kuingia →',
            'co_hint' => 'Kila kampuni inaingia kwenye anwani yake. Umesahau? Iko kwenye email ya kukaribisha.',
            'co_not_found' => 'Hatukupata kampuni yenye anwani hiyo.', 'co_bad' => 'Andika herufi ndogo, namba na kistari tu.', 'co_busy' => 'Hatukuweza kukagua sasa. Jaribu tena.',
            'm_need' => 'Andika jina lako na ujumbe.',
            'seg' => [
                'shop' => ['Maduka', 'Uza haraka, jua stoo yako.', 'Kwa duka moja au mengi: mauzo, stock, madeni na faida.'],
                'restaurant' => ['Migahawa', 'Kutoka mezani hadi jikoni.', 'Mhudumu, keshia na mpishi — kila mmoja anaona kazi yake tu.'],
                'company' => ['Kampuni', 'Hesabu zako zinakubaliana kila wakati.', 'Mauzo, manunuzi, fedha, HR na miradi kwenye mfumo mmoja.'],
            ],
            'shop_extra' => 'Duka la mtandaoni',
        ] : [
            'home' => 'Home', 'menu' => 'Menu', 'products' => 'Products', 'login' => 'Log in', 'trial' => 'Start free trial', 'contact' => 'Contact',
            'trial_long' => 'Start your free trial', 'see_how' => 'See Smart POS', 'see_prices' => 'See prices', 'learn_more' => 'Learn more',
            'badge' => 'Smart POS · Smart BMS · English & Kiswahili',
            'hero_title' => 'Your whole business, on one screen.',
            'hero_sub' => 'Sales, stock, credit, expenses and profit — on your phone or computer, in English or Kiswahili.',
            'points' => $days > 0 ? [sprintf('%d days free', $days), 'No payment today', 'Your own private account'] : ['No payment today', 'Your own private account', 'Phone and computer'],
            'example' => 'Example', 'today' => 'Today', 'app_menu' => ['Dashboard', 'Sell', 'Products', 'Who owes me', 'Reports'],
            'k_sales' => "Today's sales", 'k_profit' => 'Gross profit', 'k_credit' => 'Credit repaid',
            'built_title' => 'Built around how you work',
            'values' => [
                ['Simple for owners', 'Made for business owners, not only accountants — in English or Kiswahili.'],
                ['Every role, its own work', 'Staff see only what they need. A waiter never sees the money, for example.'],
                ['Your data is yours', 'Every company has its own private database, backed up every day.'],
            ],
            'band_title' => 'Ready to start?', 'band_sub' => $days > 0 ? sprintf('%d days free — no payment today.', $days) : 'No payment today.',
            'band_sub_closed' => 'Talk to us and we will open your account.',
            'start_with' => 'Start with', 'ask_price' => 'Ask for prices', 'bms_approval' => 'Smart BMS accounts are reviewed by BJP before they open.',
            'what_you_get' => 'What you get', 'first_steps' => 'Your first steps',
            'rest_kicker' => 'Restaurants', 'rest_title' => 'Smart POS for restaurants', 'rest_sub' => 'Choose "Restaurant" when you sign up: your first shop opens in Restaurant mode. Waiter, cashier and cook — each sees only their own work.',
            'rest_list' => ['Tables and orders', 'Kitchen screen', 'Menu and recipes', 'Cost per plate', 'QR menu', 'Waste report'],
            'price_title' => 'Clear prices. No surprises.', 'price_sub' => $days > 0 ? sprintf('Every plan starts with %d days free. Change plan any time.', $days) : 'Change plan any time.',
            'no_prices' => 'Prices are being prepared', 'no_prices_sub' => 'Talk to us and we will share our prices.',
            'billing' => 'Billing period', 'popular' => 'Most popular', 'per_month' => 'a month', 'not_term' => 'Not offered for this length',
            'after_trial' => $days > 0 ? sprintf('after your %d free days', $days) : 'per period', 'compare' => 'Compare plans', 'feature' => 'Feature',
            'faq_title' => 'Frequently asked questions', 'faq_sub' => 'Pick a topic on the left.', 'topics' => 'Topics',
            'still_q' => 'Still have a question?', 'still_q_sub' => 'We are happy to help.',
            'contact_title' => 'Talk to us', 'contact_sub' => 'We help you choose and get started.', 'phone' => 'Phone', 'hrs' => 'Hrs', 'hours' => 'Opening hours',
            'form_title' => 'Send us a message', 'form_sub_wa' => 'Your message opens in WhatsApp, ready to send.', 'form_sub_mail' => 'Your message opens in your email, ready to send.',
            'f_name' => 'Your name', 'f_biz' => 'Type of business', 'biz_opts' => ['Shop', 'Restaurant', 'Company', 'Other'], 'f_msg' => 'Message',
            'send_wa' => 'Send on WhatsApp', 'send_mail' => 'Send by email', 'no_contacts' => 'Our contact details will appear here soon.', 'call_us' => 'Give us a call.',
            'foot_about' => 'Sales and business management for shops, restaurants and companies.',
            'privacy' => 'Privacy Policy', 'staff_login' => 'BJP staff: sign in here', 'close' => 'Close',
            'co_addr' => "Your company's web address", 'co_ph' => 'your-company', 'co_find' => 'Find my company', 'co_go' => 'Continue to sign in →',
            'co_hint' => 'Every company signs in on its own address. Forgot it? It is in your welcome email.',
            'co_not_found' => 'We could not find a company with that address.', 'co_bad' => 'Use lowercase letters, numbers and hyphens only.', 'co_busy' => 'We could not check right now. Please try again.',
            'm_need' => 'Please enter your name and a message.',
            'seg' => [
                'shop' => ['Shops', 'Sell fast, know your stock.', 'For one shop or many: sales, stock, credit and profit.'],
                'restaurant' => ['Restaurants', 'From table to kitchen.', 'Waiter, cashier and cook — each sees only their own work.'],
                'company' => ['Companies', 'Your numbers always agree.', 'Sales, purchases, finance, HR and projects in one system.'],
            ],
            'shop_extra' => 'Online shop',
        ];
    }
}

if (!function_exists('landingFaqs')) {
    /** @return array{cats: array<string,string>, faqs: array<string, list<array{0:string,1:string}>>} */
    function landingFaqs(bool $sw, int $trialDays): array
    {
        $d = max(0, $trialDays);
        $payToday = $sw
            ? ($d > 0 ? sprintf('Hapana. Kila mpango unaanza na siku %d bure. Unalipa tu ukiamua kuendelea.', $d) : 'Hapana. Unalipa tu ukiamua kuendelea.')
            : ($d > 0 ? sprintf('No. Every plan starts with %d days free. You pay only if you decide to continue.', $d) : 'No. You pay only if you decide to continue.');
        return $sw ? [
            'cats' => ['general' => 'Jumla', 'billing' => 'Bei na malipo', 'data' => 'Taarifa na usalama', 'restaurant' => 'Mgahawa'],
            'faqs' => [
                'general' => [['Je, ninalipa leo?', $payToday], ['Inafanya kazi kwenye simu?', 'Ndiyo — simu na kompyuta.'], ['Naweza kutumia Kiswahili?', 'Ndiyo. Kila mtumiaji anachagua Kiingereza au Kiswahili.']],
                'billing' => [['Naweza kubadilisha mpango?', 'Ndiyo, kutoka ukurasa wako wa Subscription.'],
                              ['Ninalipaje?', 'Chagua mpango kwenye ukurasa wako wa Subscription. BJP inakutumia ankara; ikishalipwa, muda wako unaongezwa wenyewe.'],
                              ['Majaribio yakiisha?', 'Chagua mpango kwenye ukurasa wa Subscription ili kuendelea. Ongea nasi ukihitaji muda zaidi.']],
                'data' => [['Taarifa zangu ziko salama?', 'Kila kampuni ina akaunti na database yake binafsi. Hakuna kampuni nyingine inayoziona.'],
                           ['Wafanyakazi wanaona kila kitu?', 'Hapana. Kila jukumu linaona kazi yake tu.'], ['Mna backup?', 'Ndiyo, nakala inahifadhiwa kila siku.']],
                'restaurant' => [['Inafaa mgahawa?', 'Ndiyo. Chagua "Mgahawa" unapojisajili: duka lako la kwanza linafunguka katika hali ya Mgahawa — meza, skrini ya jikoni na menyu.'],
                                 ['Mhudumu anaona pesa?', 'Hapana. Mhudumu anaweka oda; keshia anapokea malipo.'],
                                 ['Wateja wanaweza kuagiza kwa QR?', 'Ndiyo, kupitia menyu ya mtandaoni na QR ya meza.']],
            ],
        ] : [
            'cats' => ['general' => 'General', 'billing' => 'Pricing & payment', 'data' => 'Data & security', 'restaurant' => 'Restaurants'],
            'faqs' => [
                'general' => [['Do I pay today?', $payToday], ['Does it work on my phone?', 'Yes — on phone and computer.'], ['Can I use Kiswahili?', 'Yes. Each user chooses English or Kiswahili.']],
                'billing' => [['Can I change plan later?', 'Yes, from your Subscription page.'],
                              ['How do I pay?', 'Choose a plan on your Subscription page. BJP sends you an invoice; once it is paid, your subscription is extended automatically.'],
                              ['What happens after the trial?', 'Choose a plan on your Subscription page to keep going. Talk to us if you need more time.']],
                'data' => [['Is my data private?', 'Every company has its own private account and database. No other company sees it.'],
                           ['Do my staff see everything?', 'No. Each role sees only its own work.'], ['Do you keep backups?', 'Yes, your data is backed up every day.']],
                'restaurant' => [['Does it fit a restaurant?', 'Yes. Choose "Restaurant" when you sign up: your first shop opens in Restaurant mode — tables, kitchen screen and menu.'],
                                 ['Does a waiter see the money?', 'No. A waiter takes orders; the cashier takes payment.'],
                                 ['Can customers order by QR?', 'Yes, through the online menu and table QR.']],
            ],
        ];
    }
}

if (!function_exists('landingProducts')) {
    /** Live offers shaped for the page — same feature lines and term labels as register.php. */
    function landingProducts(bool $sw): array
    {
        $copyAll = bmsProductCopy($sw);
        $L = $sw ? ['shop_1' => 'Duka 1', 'shops_n' => 'Hadi maduka %s', 'shops_unl' => 'Maduka bila kikomo', 'storage_gb' => 'GB %s za kuhifadhi', 'storage_unl' => 'Uhifadhi bila kikomo',
                    'r_shops' => 'Maduka', 'r_storage' => 'Uhifadhi', 'unl' => 'Bila kikomo']
                 : ['shop_1' => '1 shop', 'shops_n' => 'Up to %s shops', 'shops_unl' => 'Unlimited shops', 'storage_gb' => '%s GB storage', 'storage_unl' => 'Unlimited storage',
                    'r_shops' => 'Shops', 'r_storage' => 'Storage', 'unl' => 'Unlimited'];
        $gb = fn($mb) => rtrim(rtrim(number_format($mb / 1000, 1), '0'), '.');
        $out = [];
        try { $offers = listPublicOffers(null, true); } catch (Throwable $e) { error_log('landingProducts: ' . $e->getMessage()); $offers = []; }
        foreach ($offers as $prod) {
            if (empty($prod['plans'])) continue;
            $copy = $copyAll[$prod['product_key']] ?? array_merge($copyAll['bms_shop'], ['desc' => (string)($prod['description'] ?? '')]);
            $plans = []; $terms = []; $default = null;
            $items = [];   // compare: label => [planIndex => value]
            foreach (array_values($prod['plans']) as $pi => $p) {
                $feats = [];
                if ($p['max_shops'] !== null) $feats[] = (int)$p['max_shops'] === 1 ? $L['shop_1'] : sprintf($L['shops_n'], number_format((int)$p['max_shops']));
                elseif ($prod['product_key'] === 'bms_shop') $feats[] = $L['shops_unl'];
                $feats[] = $p['max_storage_mb'] === null ? $L['storage_unl'] : sprintf($L['storage_gb'], $gb($p['max_storage_mb']));
                foreach ($p['items'] as $it) {
                    $v = ($it['value'] !== null && $it['value'] !== '') ? (string)$it['value'] : '';
                    $feats[] = $it['label'] . ($v !== '' ? ': ' . $v : '');
                    $items[(string)$it['label']][$pi] = $v !== '' ? $v : '✓';
                }
                $prices = [];
                foreach ($p['prices'] as $pr) {
                    $m = (int)$pr['months'];
                    $label = $sw ? ($m % 12 === 0 ? 'Mwaka ' . ($m / 12) : ($m === 1 ? 'Mwezi 1' : 'Miezi ' . $m)) : (string)$pr['term_label'];
                    $prices[$pr['term_key']] = ['label' => $label, 'months' => $m, 'price' => (int)$pr['price_tzs'], 'per_month' => (int)$pr['per_month']];
                    $terms[$pr['term_key']] = ['label' => $label, 'months' => $m];
                    if (!empty($pr['is_default']) && $default === null) $default = $pr['term_key'];
                }
                $plans[] = ['key' => $p['plan_key'], 'name' => $p['name'], 'tagline' => (string)($p['tagline'] ?? ''), 'popular' => (bool)$p['is_popular'],
                            'feats' => $feats, 'prices' => $prices, 'max_shops' => $p['max_shops'], 'max_storage_mb' => $p['max_storage_mb']];
            }
            uasort($terms, fn($a, $b) => $a['months'] <=> $b['months']);
            if ($default === null || !isset($terms[$default])) $default = (string)array_key_first($terms);
            // Compare table: shops, storage, then every plan item (value, ✓ or —) — only what the plans really hold.
            $n = count($plans); $cmp = [];
            if ($prod['product_key'] === 'bms_shop' || array_filter(array_column($plans, 'max_shops'), fn($v) => $v !== null)) {
                $cmp[] = ['label' => $L['r_shops'], 'cells' => array_map(fn($p) => $p['max_shops'] === null ? $L['unl'] : number_format((int)$p['max_shops']), $plans)];
            }
            $cmp[] = ['label' => $L['r_storage'], 'cells' => array_map(fn($p) => $p['max_storage_mb'] === null ? $L['unl'] : $gb($p['max_storage_mb']) . ' GB', $plans)];
            foreach ($items as $label => $byPlan) {
                $cells = [];
                for ($i = 0; $i < $n; $i++) $cells[] = $byPlan[$i] ?? '—';
                $cmp[] = ['label' => $label, 'cells' => $cells];
            }
            $out[] = ['key' => $prod['product_key'], 'name' => (string)$prod['name'], 'copy' => $copy, 'plans' => $plans,
                      'terms' => $terms, 'default_term' => $default, 'compare' => $cmp];
        }
        return $out;
    }
}

if (!function_exists('landingData')) {
    function landingData(bool $sw): array
    {
        $trial = 0;
        try { $trial = (int)tenantDefaultTrialDays(); } catch (Throwable $e) { $trial = 0; }
        $open = false;
        try { $open = selfRegistrationClosedReason() === null; } catch (Throwable $e) { $open = false; }
        $base = '';
        try { $base = (tenantModeEnabled() && tenantBaseDomain()) ? (string)tenantBaseDomain() : ''; } catch (Throwable $e) { $base = ''; }

        $products = landingProducts($sw);
        $byKey = [];
        foreach ($products as $p) $byKey[$p['key']] = $p;

        $T = landingCopy($sw, $trial);
        $copy = bmsProductCopy($sw);
        $segments = [
            ['key' => 'shop', 'name' => $T['seg']['shop'][0], 'head' => $T['seg']['shop'][1], 'sub' => $T['seg']['shop'][2], 'product' => 'Smart POS', 'page' => 'smart-pos',
             'feats' => array_merge($copy['bms_shop']['card'], [$T['shop_extra']])],
            ['key' => 'restaurant', 'name' => $T['seg']['restaurant'][0], 'head' => $T['seg']['restaurant'][1], 'sub' => $T['seg']['restaurant'][2], 'product' => 'Smart POS', 'page' => 'smart-pos',
             'feats' => $T['rest_list']],
            ['key' => 'company', 'name' => $T['seg']['company'][0], 'head' => $T['seg']['company'][1], 'sub' => $T['seg']['company'][2], 'product' => 'Smart BMS', 'page' => 'smart-bms',
             'feats' => $copy['bms_full']['card']],
        ];
        $f = landingFaqs($sw, $trial);
        return ['products' => $products, 'products_by_key' => $byKey, 'trial_days' => $trial, 'signup_open' => $open,
                'base_domain' => $base, 'segments' => $segments, 'faqs' => $f['faqs'], 'faq_cats' => $f['cats']];
    }
}

if (!function_exists('landingMeta')) {
    /** <title> and description: each page names "Smart POS" / "Smart BMS" the way people search for them. */
    function landingMeta(string $path, bool $sw, array $data, array $site): array
    {
        $m = $sw ? [
            ''          => ['Smart BMS — Mfumo wa mauzo (POS) na usimamizi wa biashara', 'Smart POS na Smart BMS: mauzo, stock, madeni, matumizi na faida kwa maduka, migahawa na kampuni — kwa simu au kompyuta, kwa Kiswahili.'],
            'smart-pos' => ['Smart POS — mfumo wa mauzo kwa maduka na migahawa | Smart BMS', 'Smart POS: uza haraka na risiti, pokea stock, fuatilia wanaokudai na uone faida. Hali ya Mgahawa: meza, jikoni na menyu.'],
            'smart-bms' => ['Smart BMS — mfumo kamili wa usimamizi wa biashara', 'Smart BMS: mauzo, nukuu na ankara, manunuzi, fedha na ripoti za fedha, HR na mishahara, miradi na CRM kwenye mfumo mmoja.'],
            'pricing'   => ['Bei za Smart POS na Smart BMS', 'Mipango na bei za Smart POS na Smart BMS. Kila mpango unaanza na majaribio ya bure.'],
            'faq'       => ['Maswali yanayoulizwa | Smart BMS', 'Majibu kuhusu malipo, majaribio ya bure, usalama wa taarifa na Smart POS kwa migahawa.'],
            'contact'   => ['Wasiliana nasi | Smart BMS', 'Ongea na ' . $site['legal_name'] . ' kuhusu Smart POS na Smart BMS — simu, WhatsApp au email.'],
        ] : [
            ''          => ['Smart BMS — Smart POS and business management software', 'Smart POS and Smart BMS: sales, stock, credit, expenses and profit for shops, restaurants and companies — on phone or computer, in English or Kiswahili.'],
            'smart-pos' => ['Smart POS — point of sale for shops and restaurants | Smart BMS', 'Smart POS: fast selling with receipts, stock receiving, credit sales and profit. Restaurant mode: tables, kitchen screen and menu.'],
            'smart-bms' => ['Smart BMS — complete business management system', 'Smart BMS: sales, quotations and invoices, purchasing, finance and financial reports, HR and payroll, projects and CRM in one system.'],
            'pricing'   => ['Smart POS and Smart BMS pricing', 'Plans and prices for Smart POS and Smart BMS. Every plan starts with a free trial.'],
            'faq'       => ['Frequently asked questions | Smart BMS', 'Answers about payment, the free trial, data security and Smart POS for restaurants.'],
            'contact'   => ['Contact us | Smart BMS', 'Talk to ' . $site['legal_name'] . ' about Smart POS and Smart BMS — phone, WhatsApp or email.'],
        ];
        [$title, $desc] = $m[$path] ?? $m[''];
        return ['title' => $title, 'description' => $desc];
    }
}

if (!function_exists('landingJsonLd')) {
    /** Structured data — only facts the page shows. Prices only when Superadmin has published plans. */
    function landingJsonLd(string $path, bool $sw, array $data, array $site, callable $abs): array
    {
        $lang = $sw ? 'sw' : 'en';
        $org = ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => $site['legal_name'], 'url' => $site['base'] . '/',
                'logo' => $site['base'] . '/assets/images/favicon.png'];
        if ($site['address'] !== '') $org['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $site['address']];
        $cp = [];
        if ($site['phone'] !== '') $cp['telephone'] = $site['phone'];
        if ($site['email'] !== '') $cp['email'] = $site['email'];
        if ($cp) $org['contactPoint'] = ['@type' => 'ContactPoint', 'contactType' => 'customer support', 'availableLanguage' => ['English', 'Swahili']] + $cp;

        $out = [];
        if ($path === '') {
            $out[] = $org;
            $out[] = ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'Smart BMS', 'url' => $site['base'] . '/', 'inLanguage' => $lang];
        }
        if ($path === 'smart-pos' || $path === 'smart-bms') {
            $pk = $path === 'smart-pos' ? 'bms_shop' : 'bms_full';
            $app = ['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => $path === 'smart-pos' ? 'Smart POS' : 'Smart BMS',
                    'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web', 'url' => $abs($path, $lang),
                    'description' => bmsProductCopy($sw)[$pk]['sub'], 'publisher' => ['@type' => 'Organization', 'name' => $site['legal_name']]];
            $prices = [];
            foreach ($data['products_by_key'][$pk]['plans'] ?? [] as $pl) foreach ($pl['prices'] as $px) if ($px['price'] > 0) $prices[] = $px['price'];
            if ($prices) {
                $app['offers'] = ['@type' => 'AggregateOffer', 'priceCurrency' => 'TZS', 'lowPrice' => min($prices), 'highPrice' => max($prices), 'offerCount' => count($prices)];
            }
            $out[] = $app;
        }
        if ($path === 'faq') {
            $cat = array_key_exists($_GET['topic'] ?? '', $data['faq_cats']) ? $_GET['topic'] : 'general';
            $out[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'inLanguage' => $lang,
                      'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $data['faqs'][$cat])];
        }
        return $out;
    }
}
