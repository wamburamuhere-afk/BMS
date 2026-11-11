<?php
/**
 * core/landing_content.php — data, wording and search-engine details for the public website (landing.php).
 *
 * landingData()   live: products/plans/prices/terms/limits (listPublicOffers — Superadmin › Plans),
 *                 free-trial days, whether sign-up is open, the company-address domain, FAQ
 * landingCopy()   the page wording, English and Kiswahili, key for key (tests/test_landing_cli.php
 *                 checks both languages have the same keys, nothing empty). Every statement describes
 *                 something the system really does (checked 2026-11-11); figures on the example screens
 *                 are labelled "Example".
 * landingMeta()   <title> + description per page
 * landingJsonLd() structured data (Organization, WebSite, SoftwareApplication with live prices, FAQPage)
 *
 * Plans are shown exactly as the sign-up page shows them (same feature lines, same term labels).
 * Redesign 2026-11-11: Products menu (Smart BMS; Smart POS → Retail shop / Restaurant), one language
 * shown at a time, separate Retail and Restaurant pages, footer about the business.
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
            // header, menus, shared
            'home' => 'Mwanzo', 'menu' => 'Menyu', 'menu_open' => 'Fungua menyu', 'language' => 'Lugha',
            'products' => 'Bidhaa', 'pricing' => 'Bei', 'faq' => 'Maswali', 'contact' => 'Wasiliana',
            'login' => 'Ingia', 'trial' => 'Anza bure', 'trial_long' => 'Anza majaribio ya bure', 'close' => 'Funga',
            'see_prices' => 'Ona bei', 'ask_price' => 'Uliza bei', 'see_products' => 'Tazama bidhaa zetu',
            'm_bms_d' => 'Kwa kampuni zinazohitaji uhasibu kamili.', 'm_bms_s' => 'Uhasibu, mauzo, manunuzi, HR na mishahara',
            'm_pos_d' => 'Kuuza kwa urahisi. Chagua aina ya biashara yako.',
            'm_ret' => 'Duka la rejareja', 'm_ret_s' => 'Maduka, famasi, hardware, boutique',
            'm_res' => 'Mgahawa', 'm_res_s' => 'Migahawa, café, jikoni za hoteli',
            // home
            'pill_new' => 'Mpya', 'pill' => 'Smart POS sasa ina hali ya Mgahawa',
            'h_a' => 'Endesha', 'h_words' => ['duka lako', 'mgahawa wako', 'kampuni yako'], 'h_b' => 'kwa njia ya kisasa.',
            'hero_sub' => 'Smart POS kwa mauzo, stock na madeni ya maduka na migahawa. Smart BMS kampuni yako inapohitaji uhasibu kamili. Kwa simu au kompyuta, kwa Kiswahili au Kiingereza.',
            'points' => $days > 0 ? [sprintf('Siku %d bure', $days), 'Hakuna malipo leo', 'Akaunti yako binafsi'] : ['Hakuna malipo leo', 'Akaunti yako binafsi', 'Simu na kompyuta'],
            'example' => 'Mfano', 'today' => 'Leo', 'app_menu' => ['Dashibodi', 'Uza', 'Bidhaa', 'Wanaodaiwa', 'Ripoti'],
            'k_sales' => 'Mauzo ya leo', 'k_profit' => 'Faida ghafi', 'k_credit' => 'Madeni yaliyolipwa',
            'k_up' => '▲ 12% ukilinganisha na jana', 'k_up2' => '▲ 8%', 'k_who' => 'Wateja 3',
            'feed' => [['Mauzo mapya', '35,000 TZS'], ['Deni limelipwa', '20,000 TZS'], ['Mauzo mapya', '12,500 TZS'], ['Oda ya mtandaoni', 'Imekubaliwa · 18,000 TZS']],
            'rc_title' => 'Risiti #0142', 'rc_items' => [['Sukari kg 1', '3,500'], ['Mchele kg 0.5', '1,600'], ['Sabuni ×2', '2,400']], 'rc_total' => 'Jumla',
            'choose_k' => 'Chagua inayokufaa', 'choose_h' => 'Mfumo mmoja, kwa aina ya biashara yako',
            'cards' => [
                'retail'     => ['Duka la rejareja', 'Uza haraka, jua kilichopo stoo na nani anakudai.', ['Risiti za kuchapisha au email', 'Stock na kupokea mzigo', 'Mauzo ya mkopo'], 'Angalia Duka la rejareja'],
                'restaurant' => ['Mgahawa', 'Kutoka mezani, hadi jikoni, hadi bili.', ['Meza na oda', 'Skrini ya jikoni', 'Menyu, mapishi na oda kwa QR'], 'Angalia Mgahawa'],
                'company'    => ['Kampuni', 'Uhasibu kamili, kila idara imeunganishwa.', ['Trial Balance, Faida na Hasara, Mizania', 'Ankara, manunuzi na benki', 'HR na mishahara'], 'Angalia Smart BMS'],
            ],
            'values' => [
                ['Rahisi kwa mmiliki', 'Imetengenezwa kwa wamiliki wa biashara, si wahasibu tu — kwa Kiswahili au Kiingereza.'],
                ['Kila jukumu, kazi yake', 'Wafanyakazi wanaona wanachohitaji tu. Mhudumu wa meza haoni pesa.'],
                ['Taarifa zako ni zako', 'Kila kampuni ina database yake binafsi, na nakala inahifadhiwa kila siku.'],
            ],
            'band_title' => 'Uko tayari kuanza?', 'band_sub' => $days > 0 ? sprintf('Siku %d bure — hakuna malipo leo.', $days) : 'Hakuna malipo leo.',
            'band_sub_closed' => 'Ongea nasi tukufungulie akaunti.',
            // product pages (shared)
            'what_you_get' => 'Unachopata', 'first_steps' => 'Hatua zako za kwanza baada ya kuingia', 'signup_h' => 'Jisajili kwa hatua 4 fupi',
            'bms_approval' => 'BJP inakagua kila akaunti ya Smart BMS kabla ya kufunguliwa.',
            // Smart POS overview
            'pos_hub_k' => 'Smart POS', 'pos_hub_h' => 'Mfumo wa mauzo, kwa jinsi unavyouza.',
            'pos_hub_sub' => 'Smart POS inafanya kazi kwa maduka na migahawa. Chagua yako ili uone inavyokufaa.',
            'pos_same' => 'Mipango ni ileile kwa duka na mgahawa. Unachagua Duka au Mgahawa unapojisajili.',
            // Retail
            'ret_k' => 'Smart POS · Duka la rejareja', 'ret_feats_h' => 'Kila kitu duka linahitaji',
            'ret_feats' => [
                ['bolt', 'Kuuza haraka', 'Risiti ya kuchapisha au ya email kwa sekunde.'],
                ['box', 'Stock unayoiamini', 'Bidhaa, huduma na kupokea mzigo.'],
                ['scale', 'Uza kwa uzito au ujazo', 'Nusu kilo, robo lita — mashine ya mauzo inakubali.'],
                ['credit', 'Mauzo ya mkopo', 'Ona nani anakudai na rekodi anacholipa.'],
                ['chart', 'Matumizi na faida', 'Ripoti za siku, wiki, mwezi, robo mwaka na mwaka.'],
                ['cart', 'Duka lako la mtandaoni', 'Shiriki kiungo; wateja wanaagiza, wewe unathibitisha.'],
            ],
            'till_title' => 'Uza', 'till_items' => [['Sukari', 'kg 1', 3500], ['Mchele', 'kg 0.5', 1600], ['Sabuni ya kuogea', '×2', 2400], ['Mafuta ya kupikia', 'lita 1', 6000]], 'till_total' => 'Jumla',
            'ret_steps' => ['Mpango', 'Duka lako (Duka limechaguliwa)', 'Akaunti ya admin', 'Duka lako la kwanza liko tayari'],
            // Restaurant
            'res_k' => 'Smart POS · Mgahawa', 'res_feats_h' => 'Imejengwa kwa jiko lenye shughuli nyingi',
            'flow_h' => 'Oda inavyosafiri',
            'flow' => [['Mhudumu', 'anachukua oda'], ['Keshia', 'anathibitisha'], ['Jikoni', 'wanapika'], ['Mhudumu', 'anapeleka mezani'], ['Bili', 'inalipwa']],
            'flow_note' => 'Skrini ya jikoni na uthibitisho wa keshia unaviwasha kwenye Settings, ukivihitaji.',
            'tickets' => [['Meza 4', '12:41', '2 × Pilau · 1 × Juisi', 'Inapikwa'], ['Meza 7', '12:38', '1 × Chipsi mayai', 'Tayari kupelekwa']],
            'res_feats' => [
                ['table', 'Meza na oda', 'Fungua meza, ongeza vyakula, tuma jikoni.'],
                ['screen', 'Skrini ya jikoni', 'Wapishi wanaona kila oda na kuiweka tayari.'],
                ['book', 'Menyu yenye picha na mapishi', 'Jua gharama ya viungo vya kila chakula.'],
                ['qr', 'Menyu kwa QR mezani', 'Wateja wanaagiza kwa simu zao; wafanyakazi wanakubali kwanza.'],
                ['truck', 'Nunua kwa mkopo, lipa baadaye', 'Manunuzi kwa wasambazaji unayolipa ukiwa tayari.'],
                ['trash', 'Upotevu unarekodiwa', 'Chakula kilichoharibika kinaondolewa, hivyo faida yako ni ya kweli.'],
            ],
            'res_steps' => ['Mpango', 'Mgahawa wako (Mgahawa umechaguliwa)', 'Akaunti ya admin', 'Hali ya Mgahawa imewashwa; majukumu ya Mhudumu, Keshia, Mpishi na Meneja wa Mgahawa yako tayari'],
            // Smart BMS
            'bms_k' => 'Smart BMS · Mfumo kamili', 'bms_h' => 'Uhasibu wa kweli, biashara yako yote imeunganishwa.',
            'bms_sub' => 'Kila mauzo, manunuzi, malipo na mshahara vinaingia kwenye leja moja ya double-entry, hivyo Trial Balance, Faida na Hasara, na Mizania vinakubaliana kila wakati.',
            'tb_title' => 'Trial Balance', 'tb_bal' => '✓ Imesawazika', 'tb_cols' => ['Akaunti', 'Debit', 'Credit'], 'tb_total' => 'Jumla',
            'tb_rows' => [['1-1100', 'Fedha benki', 8450000, 0], ['1-1200', 'Wadaiwa wa biashara', 3120000, 0], ['2-1100', 'Wadai wa biashara', 0, 2270000], ['4-1000', 'Mauzo', 0, 14620000], ['6-1000', 'Mishahara', 5320000, 0]],
            'tb_note' => 'Mfano, kwa TZS. Debit ni sawa na credit.',
            'stmts' => ['Chart of accounts', 'Journals', 'Faida na Hasara', 'Mizania', 'Mtiririko wa fedha', 'Akaunti za benki'],
            'bms_feats_h' => 'Kila idara, kitabu kimoja cha hesabu',
            'bms_feats' => [
                ['ledger', 'Uhasibu', 'Chart of accounts, journals na ripoti zote za fedha.'],
                ['invoice', 'Mauzo', 'Nukuu, ankara na malipo ya wateja.'],
                ['truck', 'Manunuzi', 'Wasambazaji, bili na maghala.'],
                ['bank', 'Benki na matumizi', 'Kila shilingi inayoingia na kutoka inarekodiwa.'],
                ['users', 'HR na mishahara', 'Taarifa za wafanyakazi, payslip na mishahara.'],
                ['grid', 'Miradi, mali na CRM', 'Pamoja na Point of Sale.'],
            ],
            'bms_steps' => ['Mpango', 'Kampuni yako', 'Akaunti ya admin', 'BJP inakagua na kufungua akaunti yako'],
            // pricing
            'price_title' => 'Bei wazi. Hakuna mshangao.', 'price_sub' => $days > 0 ? sprintf('Kila mpango unaanza na siku %d bure. Badilisha mpango wakati wowote.', $days) : 'Badilisha mpango wakati wowote.',
            'no_prices' => 'Bei zinaandaliwa', 'no_prices_sub' => 'Ongea nasi tukueleze bei.',
            'billing' => 'Muda wa malipo', 'popular' => 'Maarufu zaidi', 'per_month' => 'kwa mwezi', 'not_term' => 'Haupo kwa muda huu',
            'after_trial' => $days > 0 ? sprintf('baada ya siku %d bure', $days) : 'kwa kipindi', 'compare' => 'Linganisha mipango', 'feature' => 'Kipengele',
            // faq + contact
            'faq_title' => 'Maswali yanayoulizwa', 'faq_sub' => 'Chagua mada.', 'topics' => 'Mada',
            'still_q' => 'Bado una swali?', 'still_q_sub' => 'Tuko tayari kukusaidia.',
            'contact_title' => 'Ongea nasi', 'contact_sub' => 'Tunakusaidia kuchagua na kuanza.', 'phone' => 'Simu', 'email' => 'Email', 'hrs' => 'Saa', 'hours' => 'Saa za kazi',
            'form_title' => 'Tutumie ujumbe', 'form_sub_wa' => 'Ujumbe wako utafunguka kwenye WhatsApp, tayari kutumwa.', 'form_sub_mail' => 'Ujumbe wako utafunguka kwenye email yako, tayari kutumwa.',
            'f_name' => 'Jina lako', 'f_biz' => 'Aina ya biashara', 'biz_opts' => ['Duka', 'Mgahawa', 'Kampuni', 'Nyingine'], 'f_msg' => 'Ujumbe',
            'send_wa' => 'Tuma kwa WhatsApp', 'send_mail' => 'Tuma kwa email', 'no_contacts' => 'Mawasiliano yataonekana hapa hivi karibuni.', 'call_us' => 'Tupigie simu.',
            'm_need' => 'Andika jina lako na ujumbe.',
            // footer
            'foot_about' => 'Smart POS inaendesha mauzo ya maduka na migahawa. Smart BMS inazipa kampuni zinazokua uhasibu kamili, mishahara na ripoti za fedha.',
            'f_products' => 'Bidhaa', 'f_help' => 'Msaada', 'f_talk' => 'Ongea nasi', 'f_acct' => 'Uhasibu', 'privacy' => 'Sera ya Faragha',
            // log in window
            'staff_login' => 'Wafanyakazi wa BJP: ingia hapa',
            'co_addr' => 'Anwani ya kampuni yako', 'co_ph' => 'jina-la-kampuni', 'co_find' => 'Tafuta kampuni yangu', 'co_go' => 'Endelea kuingia →',
            'co_hint' => 'Kila kampuni inaingia kwenye anwani yake. Umesahau? Iko kwenye email ya kukaribisha.',
            'co_not_found' => 'Hatukupata kampuni yenye anwani hiyo.', 'co_bad' => 'Andika herufi ndogo, namba na kistari tu.', 'co_busy' => 'Hatukuweza kukagua sasa. Jaribu tena.',
        ] : [
            // header, menus, shared
            'home' => 'Home', 'menu' => 'Menu', 'menu_open' => 'Open menu', 'language' => 'Language',
            'products' => 'Products', 'pricing' => 'Pricing', 'faq' => 'FAQ', 'contact' => 'Contact',
            'login' => 'Log in', 'trial' => 'Start free trial', 'trial_long' => 'Start your free trial', 'close' => 'Close',
            'see_prices' => 'See prices', 'ask_price' => 'Ask for prices', 'see_products' => 'See our products',
            'm_bms_d' => 'For companies that need full accounting.', 'm_bms_s' => 'Accounting, sales, purchases, HR and payroll',
            'm_pos_d' => 'Selling made simple. Pick your kind of business.',
            'm_ret' => 'Retail shop', 'm_ret_s' => 'Shops, pharmacies, hardware, boutiques',
            'm_res' => 'Restaurant', 'm_res_s' => 'Restaurants, cafés, hotel kitchens',
            // home
            'pill_new' => 'New', 'pill' => 'Smart POS now has a Restaurant mode',
            'h_a' => 'Run your', 'h_words' => ['shop', 'restaurant', 'company'], 'h_b' => 'the smart way.',
            'hero_sub' => 'Smart POS for sales, stock and credit in shops and restaurants. Smart BMS when your company needs full accounting. On your phone or computer, in English or Kiswahili.',
            'points' => $days > 0 ? [sprintf('%d days free', $days), 'No payment today', 'Your own private account'] : ['No payment today', 'Your own private account', 'Phone and computer'],
            'example' => 'Example', 'today' => 'Today', 'app_menu' => ['Dashboard', 'Sell', 'Products', 'Who owes me', 'Reports'],
            'k_sales' => "Today's sales", 'k_profit' => 'Gross profit', 'k_credit' => 'Credit repaid',
            'k_up' => '▲ 12% vs yesterday', 'k_up2' => '▲ 8%', 'k_who' => '3 customers',
            'feed' => [['New sale', '35,000 TZS'], ['Credit repaid', '20,000 TZS'], ['New sale', '12,500 TZS'], ['Online order', 'Accepted · 18,000 TZS']],
            'rc_title' => 'Receipt #0142', 'rc_items' => [['Sugar 1 kg', '3,500'], ['Rice 0.5 kg', '1,600'], ['Soap ×2', '2,400']], 'rc_total' => 'Total',
            'choose_k' => 'Find your fit', 'choose_h' => 'One system, made for your kind of business',
            'cards' => [
                'retail'     => ['Retail shop', 'Sell fast, know what is in stock and who owes you.', ['Receipts, printed or emailed', 'Stock and receiving', 'Credit sales'], 'See Retail shop'],
                'restaurant' => ['Restaurant', 'From the table to the kitchen to the bill.', ['Tables and orders', 'Kitchen screen', 'Menu, recipes and QR ordering'], 'See Restaurant'],
                'company'    => ['Company', 'Full accounting with every department connected.', ['Trial Balance, Profit & Loss, Balance Sheet', 'Invoices, purchases and banks', 'HR and payroll'], 'See Smart BMS'],
            ],
            'values' => [
                ['Simple for owners', 'Made for business owners, not only accountants — in English or Kiswahili.'],
                ['Each role sees its own work', 'Staff see only what they need. A waiter never sees the money.'],
                ['Your data is yours', 'Every company has its own private database, backed up every day.'],
            ],
            'band_title' => 'Ready to start?', 'band_sub' => $days > 0 ? sprintf('%d days free — no payment today.', $days) : 'No payment today.',
            'band_sub_closed' => 'Talk to us and we will open your account.',
            // product pages (shared)
            'what_you_get' => 'What you get', 'first_steps' => 'Your first steps after signing in', 'signup_h' => 'Sign up in 4 short steps',
            'bms_approval' => 'BJP reviews every Smart BMS account before it opens.',
            // Smart POS overview
            'pos_hub_k' => 'Smart POS', 'pos_hub_h' => 'A till made for the way you sell.',
            'pos_hub_sub' => 'Smart POS works for shops and for restaurants. Pick yours to see how it fits.',
            'pos_same' => 'The plans are the same for shops and restaurants. You choose Retail or Restaurant when you sign up.',
            // Retail
            'ret_k' => 'Smart POS · Retail shop', 'ret_feats_h' => 'Everything a shop needs',
            'ret_feats' => [
                ['bolt', 'Fast selling', 'Printed or emailed receipts in seconds.'],
                ['box', 'Stock you can trust', 'Products, services and receiving stock.'],
                ['scale', 'Sell by weight or volume', 'Half a kilo, a quarter litre — the till takes it.'],
                ['credit', 'Credit sales', 'See who owes you and record what they pay back.'],
                ['chart', 'Expenses and profit', 'Reports: daily, weekly, monthly, quarterly and yearly.'],
                ['cart', 'Your own online shop', 'Share a link; customers order, you confirm.'],
            ],
            'till_title' => 'Sell', 'till_items' => [['Sugar', '1 kg', 3500], ['Rice', '0.5 kg', 1600], ['Bathing soap', '×2', 2400], ['Cooking oil', '1 L', 6000]], 'till_total' => 'Total',
            'ret_steps' => ['Plan', 'Your shop (Retail ticked)', 'Admin account', 'Your first shop is ready'],
            // Restaurant
            'res_k' => 'Smart POS · Restaurant', 'res_feats_h' => 'Built for a busy kitchen',
            'flow_h' => 'How an order moves',
            'flow' => [['Waiter', 'takes the order'], ['Cashier', 'confirms'], ['Kitchen', 'cooks'], ['Waiter', 'serves'], ['Bill', 'is paid']],
            'flow_note' => 'Switch on the kitchen screen and cashier confirmation in Settings, when you need them.',
            'tickets' => [['Table 4', '12:41', '2 × Pilau · 1 × Juice', 'Cooking'], ['Table 7', '12:38', '1 × Chips mayai', 'Ready to serve']],
            'res_feats' => [
                ['table', 'Tables and orders', 'Open a table, add dishes, send to the kitchen.'],
                ['screen', 'Kitchen screen', 'Cooks see each order and mark it ready.'],
                ['book', 'Menu with photos and recipes', 'Know the ingredient cost of every dish.'],
                ['qr', 'QR menu at the table', 'Guests order from their phone; staff accept first.'],
                ['truck', 'Buy on credit, pay later', 'Supplier purchases you settle when you are ready.'],
                ['trash', 'Waste recorded', 'Spoiled food is written off, so your profit is true.'],
            ],
            'res_steps' => ['Plan', 'Your restaurant (Restaurant ticked)', 'Admin account', 'Restaurant mode on; Waiter, Cashier, Cook and Restaurant Manager roles ready'],
            // Smart BMS
            'bms_k' => 'Smart BMS · Complete system', 'bms_h' => 'Real accounting, with your whole business connected.',
            'bms_sub' => 'Every sale, purchase, payment and payslip posts to one double-entry ledger, so your Trial Balance, Profit & Loss and Balance Sheet always agree.',
            'tb_title' => 'Trial Balance', 'tb_bal' => '✓ Balanced', 'tb_cols' => ['Account', 'Debit', 'Credit'], 'tb_total' => 'Total',
            'tb_rows' => [['1-1100', 'Cash at bank', 8450000, 0], ['1-1200', 'Trade debtors', 3120000, 0], ['2-1100', 'Trade creditors', 0, 2270000], ['4-1000', 'Sales', 0, 14620000], ['6-1000', 'Salaries', 5320000, 0]],
            'tb_note' => 'Example, in TZS. Debits equal credits.',
            'stmts' => ['Chart of accounts', 'Journals', 'Profit & Loss', 'Balance Sheet', 'Cash Flow', 'Bank accounts'],
            'bms_feats_h' => 'Every department, one set of books',
            'bms_feats' => [
                ['ledger', 'Accounting', 'Chart of accounts, journals and the full financial reports.'],
                ['invoice', 'Sales', 'Quotations, invoices and customer payments.'],
                ['truck', 'Purchases', 'Suppliers, bills and warehouses.'],
                ['bank', 'Banks and expenses', 'Every shilling in and out recorded.'],
                ['users', 'HR and payroll', 'Staff records, payslips and payroll.'],
                ['grid', 'Projects, assets and CRM', 'Plus a Point of Sale, included.'],
            ],
            'bms_steps' => ['Plan', 'Your company', 'Admin account', 'BJP reviews and opens your account'],
            // pricing
            'price_title' => 'Clear prices. No surprises.', 'price_sub' => $days > 0 ? sprintf('Every plan starts with %d days free. Change plan any time.', $days) : 'Change plan any time.',
            'no_prices' => 'Prices are being prepared', 'no_prices_sub' => 'Talk to us and we will share our prices.',
            'billing' => 'Billing period', 'popular' => 'Most popular', 'per_month' => 'a month', 'not_term' => 'Not offered for this length',
            'after_trial' => $days > 0 ? sprintf('after your %d free days', $days) : 'per period', 'compare' => 'Compare plans', 'feature' => 'Feature',
            // faq + contact
            'faq_title' => 'Frequently asked questions', 'faq_sub' => 'Pick a topic.', 'topics' => 'Topics',
            'still_q' => 'Still have a question?', 'still_q_sub' => 'We are happy to help.',
            'contact_title' => 'Talk to us', 'contact_sub' => 'We help you choose and get started.', 'phone' => 'Phone', 'email' => 'Email', 'hrs' => 'Hrs', 'hours' => 'Opening hours',
            'form_title' => 'Send us a message', 'form_sub_wa' => 'Your message opens in WhatsApp, ready to send.', 'form_sub_mail' => 'Your message opens in your email, ready to send.',
            'f_name' => 'Your name', 'f_biz' => 'Type of business', 'biz_opts' => ['Shop', 'Restaurant', 'Company', 'Other'], 'f_msg' => 'Message',
            'send_wa' => 'Send on WhatsApp', 'send_mail' => 'Send by email', 'no_contacts' => 'Our contact details will appear here soon.', 'call_us' => 'Give us a call.',
            'm_need' => 'Please enter your name and a message.',
            // footer
            'foot_about' => 'Smart POS runs the till for shops and restaurants. Smart BMS gives growing companies full accounting, payroll and financial reports.',
            'f_products' => 'Products', 'f_help' => 'Help', 'f_talk' => 'Talk to us', 'f_acct' => 'Accounting', 'privacy' => 'Privacy Policy',
            // log in window
            'staff_login' => 'BJP staff: sign in here',
            'co_addr' => "Your company's web address", 'co_ph' => 'your-company', 'co_find' => 'Find my company', 'co_go' => 'Continue to sign in →',
            'co_hint' => 'Every company signs in on its own address. Forgot it? It is in your welcome email.',
            'co_not_found' => 'We could not find a company with that address.', 'co_bad' => 'Use lowercase letters, numbers and hyphens only.', 'co_busy' => 'We could not check right now. Please try again.',
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
        $f = landingFaqs($sw, $trial);
        return ['products' => $products, 'products_by_key' => $byKey, 'trial_days' => $trial, 'signup_open' => $open,
                'base_domain' => $base, 'faqs' => $f['faqs'], 'faq_cats' => $f['cats']];
    }
}

if (!function_exists('landingMeta')) {
    /** <title> and description: each page names "Smart POS" / "Smart BMS" the way people search for them. */
    function landingMeta(string $path, bool $sw, array $data, array $site): array
    {
        $m = $sw ? [
            ''                     => ['Smart BMS — Smart POS na mfumo wa uhasibu kwa biashara', 'Smart POS kwa maduka na migahawa: mauzo, stock, madeni na oda za jikoni. Smart BMS kwa kampuni: uhasibu kamili, Trial Balance na mishahara. Kwa simu au kompyuta, kwa Kiswahili.'],
            'smart-pos'            => ['Smart POS — mfumo wa mauzo kwa maduka na migahawa | Smart BMS', 'Smart POS: mfumo wa mauzo kwa duka la rejareja au mgahawa. Risiti, stock, madeni, meza, skrini ya jikoni na menyu.'],
            'smart-pos/retail'     => ['Smart POS kwa duka la rejareja — mauzo, stock na madeni | Smart BMS', 'Smart POS kwa maduka: uza haraka na risiti, pokea stock, uza kwa nusu kilo, fuatilia wanaokudai, matumizi na faida, na duka la mtandaoni.'],
            'smart-pos/restaurant' => ['Smart POS kwa mgahawa — meza, jikoni na menyu | Smart BMS', 'Smart POS kwa migahawa: meza na oda, skrini ya jikoni, menyu yenye picha na mapishi, menyu kwa QR, manunuzi ya mkopo na upotevu wa chakula.'],
            'smart-bms'            => ['Smart BMS — mfumo wa uhasibu na usimamizi wa biashara', 'Smart BMS: uhasibu wa double-entry (Trial Balance, Faida na Hasara, Mizania, Mtiririko wa fedha), mauzo na ankara, manunuzi, benki, HR na mishahara, miradi na CRM.'],
            'pricing'              => ['Bei za Smart POS na Smart BMS', 'Mipango na bei za Smart POS na Smart BMS. Kila mpango unaanza na majaribio ya bure.'],
            'faq'                  => ['Maswali yanayoulizwa | Smart BMS', 'Majibu kuhusu malipo, majaribio ya bure, usalama wa taarifa na Smart POS kwa migahawa.'],
            'contact'              => ['Wasiliana nasi | Smart BMS', 'Ongea na ' . $site['legal_name'] . ' kuhusu Smart POS na Smart BMS — simu, WhatsApp au email.'],
        ] : [
            ''                     => ['Smart BMS — Smart POS and accounting software for business', 'Smart POS for shops and restaurants: sales, stock, credit and kitchen orders. Smart BMS for companies: full accounting, Trial Balance and payroll. On phone or computer, in English or Kiswahili.'],
            'smart-pos'            => ['Smart POS — point of sale for shops and restaurants | Smart BMS', 'Smart POS: point of sale for a retail shop or a restaurant. Receipts, stock, credit sales, tables, kitchen screen and menu.'],
            'smart-pos/retail'     => ['Smart POS for retail shops — sales, stock and credit | Smart BMS', 'Smart POS for shops: fast selling with receipts, stock receiving, half-kilo sales, credit sales, expenses and profit, and your own online shop.'],
            'smart-pos/restaurant' => ['Smart POS for restaurants — tables, kitchen and menu | Smart BMS', 'Smart POS for restaurants: tables and orders, kitchen screen, menu with photos and recipes, QR menu ordering, buying on credit and food waste.'],
            'smart-bms'            => ['Smart BMS — accounting and business management system', 'Smart BMS: double-entry accounting (Trial Balance, Profit & Loss, Balance Sheet, Cash Flow), sales and invoices, purchases, banks, HR and payroll, projects and CRM.'],
            'pricing'              => ['Smart POS and Smart BMS pricing', 'Plans and prices for Smart POS and Smart BMS. Every plan starts with a free trial.'],
            'faq'                  => ['Frequently asked questions | Smart BMS', 'Answers about payment, the free trial, data security and Smart POS for restaurants.'],
            'contact'              => ['Contact us | Smart BMS', 'Talk to ' . $site['legal_name'] . ' about Smart POS and Smart BMS — phone, WhatsApp or email.'],
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
        $appPages = ['smart-pos' => 'bms_shop', 'smart-pos/retail' => 'bms_shop', 'smart-pos/restaurant' => 'bms_shop', 'smart-bms' => 'bms_full'];
        if (isset($appPages[$path])) {
            $pk = $appPages[$path];
            $desc = $path === 'smart-pos/restaurant' ? bmsRestaurantCopy($sw)['sub'] : bmsProductCopy($sw)[$pk]['sub'];
            $app = ['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => $pk === 'bms_shop' ? 'Smart POS' : 'Smart BMS',
                    'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web', 'url' => $abs($path, $lang),
                    'description' => $desc, 'publisher' => ['@type' => 'Organization', 'name' => $site['legal_name']]];
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
