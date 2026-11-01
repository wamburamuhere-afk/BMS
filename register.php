<?php
/**
 * register.php — public company self-registration (Design A, 2026-10-23).
 *
 * Standalone by design: it never boots roots.php, because there is no tenant and
 * no signed-in user to load. Reachable only on the platform's root domain — a
 * tenant's own subdomain returns 404, since a visitor there is that company's
 * customer, not a prospect.
 *
 * Steps: Product → Plan → Shop/Company → Admin account → (setting up) → ready.
 * The product and plan steps come from Superadmin's live catalogue
 * (listPublicOffers): with one product the product step is skipped, with none
 * both are skipped and the company starts on the self-registration defaults
 * (Smart POS), exactly as before. Smart BMS signups are held for approval
 * (core/tenant_registration.php, holdTenantForApproval()).
 *
 * Every marketing line per product below was checked against what that product
 * actually turns on — do not add a benefit the product does not deliver.
 */
require_once __DIR__ . '/core/tenant_registration.php';
require_once __DIR__ . '/core/tenant_lifecycle_policy.php';
require_once __DIR__ . '/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageLang = 'en';
if (!empty($_GET['lang']) && in_array($_GET['lang'], ['en', 'sw'], true)) {
    $pageLang = $_GET['lang'];
    setcookie('bms_lang', $pageLang, time() + 365 * 24 * 3600, '/');
} elseif (!empty($_COOKIE['bms_lang']) && in_array($_COOKIE['bms_lang'], ['en', 'sw'], true)) {
    $pageLang = $_COOKIE['bms_lang'];
}
$sw = $pageLang === 'sw';

$r = resolveTenantFromRequest();
if (in_array($r['status'] ?? '', ['found', 'unknown'], true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}

$closed    = selfRegistrationClosedReason();
$baseDom   = tenantBaseDomain() ?? 'example.com';
$trialDays = tenantDefaultTrialDays();
$trialEnd  = date('j M Y', strtotime('+' . $trialDays . ' days'));

// ── Copy ─────────────────────────────────────────────────────────────────────
$T = $sw ? [
    'title'          => 'Fungua Akaunti ya Kampuni',
    'have_account'   => 'Una akaunti tayari?',
    'sign_in'        => 'Ingia',
    'step_product'   => 'Bidhaa',
    'step_plan'      => 'Mpango',
    'step_admin'     => 'Akaunti ya admin',
    'step_of'        => 'HATUA %d KATI YA %d',
    'gen_head'       => 'Mfumo sahihi kwa biashara yako.',
    'gen_sub'        => 'Chagua unachohitaji leo. Kampuni yako inapata akaunti yake binafsi, tayari ndani ya dakika chache.',
    'gen_list_title' => 'KILA MPANGO UNA',
    'gen_list'       => [sprintf('Siku %d bure kwa kila mpango', $trialDays), 'Hakuna malipo leo', 'Taarifa zako ni siri ya kampuni yako', 'Inafanya kazi kwenye simu na kompyuta, kwa Kiingereza na Kiswahili'],
    'what_you_get'   => 'UNACHOPATA',
    'etc'            => 'n.k.',
    'first_steps'    => 'HATUA ZAKO ZA KWANZA BAADA YA KUINGIA',
    'your_plan'      => 'MPANGO WAKO',
    'change'         => 'Badilisha',
    'free_days'      => 'siku %d za kwanza bure',
    'product_h'      => 'Ungependa kutumia nini?',
    'product_sub'    => sprintf('Chagua mfumo unaofaa biashara yako leo. Kila mpango unaanza na siku %d bure.', $trialDays),
    'choose'         => 'Chagua %s →',
    'from'           => 'Kuanzia',
    'per_month'      => '/ mwezi',
    'not_sure'       => 'Huna uhakika? Wasiliana nasi tukusaidie kuchagua.',
    'back_login'     => '← Rudi kwenye login',
    'plan_h'         => 'Chagua mpango wa %s',
    'plan_sub'       => 'Majaribio yako ya bure yanaanza kwenye mpango huu. Unaweza kubadilisha mpango wakati wowote.',
    'popular'        => 'Maarufu zaidi',
    'not_term'       => 'Haupo kwa muda huu',
    'shop_1'         => 'Duka 1',
    'shops_n'        => 'Hadi maduka %s',
    'shops_unl'      => 'Maduka bila kikomo',
    'storage_gb'     => 'GB %s za kuhifadhi',
    'storage_unl'    => 'Uhifadhi bila kikomo',
    'pay_today'      => 'Unalipa <strong>0 TZS leo</strong>. Baada ya siku %d, endelea na <strong>%s</strong> kwa %s — ukiridhika tu.',
    'back'           => '← Rudi',
    'continue_with'  => 'Endelea na %s →',
    'continue'       => 'Endelea →',
    'web_address'    => 'Anwani yako ya mtandao',
    'sub_hint'       => 'Herufi ndogo, namba na kistari. Herufi 3–32.',
    'sub_ok'         => '✓ %s inapatikana',
    'sub_checking'   => 'Inakagua…',
    'country'        => 'Nchi',
    'phys'           => 'Anwani ya mahali',
    'postal'         => 'Anwani ya posta',
    'optional'       => '(si lazima)',
    'phys_ph'        => 'mf. Plot 12, Barabara ya Sokoine, Arusha',
    'postal_ph'      => 'mf. S.L.P 123, Moshi',
    'logo'           => 'Pakia logo',
    'logo_hint'      => 'PNG au JPG, hadi 2MB',
    'admin_h'        => 'Tengeneza akaunti yako ya admin',
    'first_name'     => 'Jina la kwanza',
    'last_name'      => 'Jina la mwisho',
    'username'       => 'Username',
    'user_ok'        => '✓ Sawa — utaingia kwa username hii',
    'user_hint'      => 'Anza na herufi; herufi, namba, nukta, kistari au underscore.',
    'phone'          => 'Namba ya simu',
    'phone_ph'       => 'mf. 0712 345 678',
    'email'          => 'Barua pepe',
    'email_hint'     => 'Inatumika kurudisha password ukisahau.',
    'password'       => 'Password',
    'password_ph'    => 'Angalau herufi 8',
    'confirm'        => 'Rudia password',
    'confirm_ph'     => 'Andika tena password',
    'chk_len'        => 'Herufi 8+',
    'chk_letter'     => 'Herufi',
    'chk_num'        => 'Namba',
    'chk_match'      => 'Password zinafanana',
    'terms'          => 'Nakubali <u>Masharti ya Huduma</u> na <u>Sera ya Faragha</u>',
    'create'         => 'Fungua akaunti yangu',
    'err_generic'    => 'Usajili umeshindikana. Tafadhali jaribu tena.',
    'err_fix'        => 'Tafadhali rekebisha sehemu %d zilizowekwa alama nyekundu hapa chini.',
    'f_name'         => 'Andika jina la kampuni (angalau herufi 2).',
    'f_sub_wait'     => 'Subiri tumalize kukagua anwani hii ya mtandao, kisha bonyeza Endelea tena.',
    'f_sub_taken'    => 'Anwani hii ya mtandao imeshachukuliwa. Chagua nyingine.',
    'f_first'        => 'Andika jina lako la kwanza.',
    'f_last'         => 'Andika jina lako la mwisho.',
    'f_user_empty'   => 'Andika username utakayotumia kuingia, mf. jane.mwangi',
    'f_user_space'   => 'Username haiwezi kuwa na nafasi. Tumia nukta badala yake, mf. jane.mwangi',
    'f_user_at'      => 'Username si email. Usitumie @ — mf. jane.mwangi',
    'f_user_start'   => 'Username lazima ianze na herufi, si namba au alama. Mf. jane2026',
    'f_user_short'   => 'Username ni fupi mno — angalau herufi 3.',
    'f_user_chars'   => 'Username inaweza kuwa na herufi, namba, nukta (.), kistari (-) au underscore (_) tu.',
    'f_phone_empty'  => 'Andika namba yako ya simu, mf. 0712 345 678',
    'f_phone_chars'  => 'Simu: tumia namba tu (na + mwanzoni ukipenda), mf. 0712 345 678 au +255 712 345 678',
    'f_phone_len'    => 'Simu: inahitaji namba 7 hadi 15. Uliyoandika ina namba %d.',
    'f_phone_taken'  => 'Namba hii ya simu tayari ina akaunti. Ingia badala yake, au tumia namba nyingine.',
    'f_email_empty'  => 'Andika email yako — ndiyo njia pekee ya kurudisha password ukisahau.',
    'f_email'        => 'Email haijakamilika — inapaswa kuwa kama jane@gmail.com',
    'f_email_taken'  => 'Email hii tayari ina akaunti. Ingia badala yake, au tumia email nyingine.',
    'f_pw_empty'     => 'Chagua password.',
    'f_pw_need'      => 'Password bado inahitaji: %s.',
    'f_pw_len'       => 'herufi 8 au zaidi',
    'f_pw_letter'    => 'angalau herufi moja',
    'f_pw_num'       => 'angalau namba moja',
    'f_pw2_empty'    => 'Andika password tena hapa kuithibitisha.',
    'f_pw2'          => 'Password mbili hazifanani. Ziandike tena zifanane.',
    'f_terms'        => 'Weka tiki kukubali Masharti ya Huduma na Sera ya Faragha.',
    'pw_show'        => 'Onyesha password',
    'pw_hide'        => 'Ficha password',
    'creating_h'     => 'Tunaandaa akaunti yako ya %s…',
    'creating_sub'   => 'Inachukua sekunde chache. Tafadhali usifunge ukurasa huu.',
    'c1'             => 'Tunatengeneza database yako binafsi',
    'c2'             => 'Tunaandaa %s',
    'c3'             => 'Tunaweka mpango wako',
    'c4'             => 'Tunatengeneza akaunti yako ya admin',
    'ready_h'        => 'Akaunti yako ya %s iko tayari',
    'trial_until'    => 'majaribio ya bure hadi %s',
    'pw_note'        => 'Ile uliyochagua sasa hivi',
    'plan_after'     => '%s baada ya majaribio',
    'sent_to'        => 'Tumetuma taarifa hizi kwenye %s.',
    'sign_in_now'    => 'Ingia sasa →',
    'pending_h'      => 'Ombi lako la Smart BMS limepokelewa',
    'pending_sub'    => 'Akaunti yako imeandaliwa na inasubiri idhini ya BJP Technologies. Tutakutumia email ikishaidhinishwa — kisha utaingia kwa username na password uliyochagua.',
    'pending_ok'     => 'Sawa',
    'wa_label'       => 'Anwani ya mtandao',
    'plan_label'     => 'Mpango',
    'welcome'        => 'Karibu %s.',
    'welcome_sub'    => 'Akaunti yako iko tayari. Ingia uanze.',
    'example'        => 'Mfano',
    'live'           => 'Moja kwa moja',
] : [
    'title'          => 'Create your company account',
    'have_account'   => 'Already have an account?',
    'sign_in'        => 'Sign in',
    'step_product'   => 'Product',
    'step_plan'      => 'Plan',
    'step_admin'     => 'Admin account',
    'step_of'        => 'STEP %d OF %d',
    'gen_head'       => 'The right system for your business.',
    'gen_sub'        => 'Pick what you need today. Your company gets its own private account, ready in minutes.',
    'gen_list_title' => 'EVERY PLAN INCLUDES',
    'gen_list'       => [sprintf('%d days free on every plan', $trialDays), 'No payment today', 'Your data stays private to your company', 'Works on phone and computer, in English and Swahili'],
    'what_you_get'   => 'WHAT YOU GET',
    'etc'            => 'etc.',
    'first_steps'    => 'YOUR FIRST STEPS AFTER SIGNING IN',
    'your_plan'      => 'YOUR PLAN',
    'change'         => 'Change',
    'free_days'      => 'first %d days free',
    'product_h'      => 'What would you like to use?',
    'product_sub'    => sprintf('Choose the system that fits your business today. Every plan starts with %d days free.', $trialDays),
    'choose'         => 'Choose %s →',
    'from'           => 'From',
    'per_month'      => '/ month',
    'not_sure'       => 'Not sure which one? Contact us and we will help you choose.',
    'back_login'     => '← Back to login',
    'plan_h'         => 'Choose your %s plan',
    'plan_sub'       => 'Your free trial starts on this plan. You can change plan any time.',
    'popular'        => 'Most popular',
    'not_term'       => 'Not offered for this length',
    'shop_1'         => '1 shop',
    'shops_n'        => 'Up to %s shops',
    'shops_unl'      => 'Unlimited shops',
    'storage_gb'     => '%s GB storage',
    'storage_unl'    => 'Unlimited storage',
    'pay_today'      => 'You pay <strong>0 TZS today</strong>. After %d days, continue on <strong>%s</strong> for %s — only if you are happy.',
    'back'           => '← Back',
    'continue_with'  => 'Continue with %s →',
    'continue'       => 'Continue →',
    'web_address'    => 'Your web address',
    'sub_hint'       => 'Lowercase letters, numbers and hyphens. 3–32 characters.',
    'sub_ok'         => '✓ %s is available',
    'sub_checking'   => 'Checking…',
    'country'        => 'Country',
    'phys'           => 'Physical address',
    'postal'         => 'Postal address',
    'optional'       => '(optional)',
    'phys_ph'        => 'e.g. Plot 12, Sokoine Road, Arusha',
    'postal_ph'      => 'e.g. P.O. Box 123, Moshi',
    'logo'           => 'Upload logo',
    'logo_hint'      => 'PNG or JPG, up to 2 MB',
    'admin_h'        => 'Create your admin account',
    'first_name'     => 'First name',
    'last_name'      => 'Last name',
    'username'       => 'Username',
    'user_ok'        => '✓ Looks good — you will sign in with this username',
    'user_hint'      => 'Start with a letter; letters, numbers, dots, hyphens or underscores.',
    'phone'          => 'Phone number',
    'phone_ph'       => 'e.g. 0712 345 678',
    'email'          => 'Email',
    'email_hint'     => 'Used to reset your password.',
    'password'       => 'Password',
    'password_ph'    => 'Min. 8 characters',
    'confirm'        => 'Confirm password',
    'confirm_ph'     => 'Repeat password',
    'chk_len'        => '8+ characters',
    'chk_letter'     => 'A letter',
    'chk_num'        => 'A number',
    'chk_match'      => 'Passwords match',
    'terms'          => 'I agree to the <u>Terms of Service</u> and <u>Privacy Policy</u>',
    'create'         => 'Create my account',
    'err_generic'    => 'Registration failed. Please try again.',
    'err_fix'        => 'Please fix the %d field(s) marked in red below.',
    'f_name'         => 'Enter the company name (at least 2 characters).',
    'f_sub_wait'     => 'Please wait while we check this web address, then press Continue again.',
    'f_sub_taken'    => 'That web address is already taken. Please choose another.',
    'f_first'        => 'Enter your first name.',
    'f_last'         => 'Enter your last name.',
    'f_user_empty'   => 'Enter the username you will sign in with, e.g. jane.mwangi',
    'f_user_space'   => 'A username cannot contain spaces. Use a dot instead, e.g. jane.mwangi',
    'f_user_at'      => 'The username is not your email. Leave out the @ — e.g. jane.mwangi',
    'f_user_start'   => 'The username must start with a letter, not a number or symbol. E.g. jane2026',
    'f_user_short'   => 'The username is too short — use at least 3 characters.',
    'f_user_chars'   => 'The username can only contain letters, numbers, dots (.), hyphens (-) or underscores (_).',
    'f_phone_empty'  => 'Enter your phone number, e.g. 0712 345 678',
    'f_phone_chars'  => 'Phone: use numbers only (with an optional + at the start), e.g. 0712 345 678 or +255 712 345 678',
    'f_phone_len'    => 'Phone: needs 7 to 15 digits. The number you typed has %d.',
    'f_phone_taken'  => 'This phone number already has an account. Sign in instead, or use another number.',
    'f_email_empty'  => 'Enter your email — it is the only way to reset your password if you forget it.',
    'f_email'        => 'The email address looks incomplete — it should look like jane@gmail.com',
    'f_email_taken'  => 'This email address already has an account. Sign in instead, or use another email.',
    'f_pw_empty'     => 'Choose a password.',
    'f_pw_need'      => 'Your password still needs: %s.',
    'f_pw_len'       => 'at least 8 characters',
    'f_pw_letter'    => 'at least one letter',
    'f_pw_num'       => 'at least one number',
    'f_pw2_empty'    => 'Type the password again here to confirm it.',
    'f_pw2'          => 'The two passwords do not match. Type them again so they are the same.',
    'f_terms'        => 'Tick the box to accept the Terms of Service and Privacy Policy.',
    'pw_show'        => 'Show password',
    'pw_hide'        => 'Hide password',
    'creating_h'     => 'Setting up your %s account…',
    'creating_sub'   => "This takes a few seconds. Please don't close this page.",
    'c1'             => 'Creating your private database',
    'c2'             => 'Setting up %s',
    'c3'             => 'Applying your plan',
    'c4'             => 'Creating your admin account',
    'ready_h'        => 'Your %s account is ready',
    'trial_until'    => 'free trial until %s',
    'pw_note'        => 'The one you just chose',
    'plan_after'     => '%s after the trial',
    'sent_to'        => 'We also sent these details to %s.',
    'sign_in_now'    => 'Sign in now →',
    'pending_h'      => 'Your Smart BMS request has been received',
    'pending_sub'    => 'Your account has been set up and is waiting for approval by BJP Technologies. We will email you as soon as it is approved — then sign in with the username and password you chose.',
    'pending_ok'     => 'OK',
    'wa_label'       => 'Web address',
    'plan_label'     => 'Plan',
    'welcome'        => 'Welcome to %s.',
    'welcome_sub'    => 'Your account is ready. Sign in to start.',
    'example'        => 'Example',
    'live'           => 'Live',
];

// What each product really delivers (verified against core/plans.php
// platformProductProfile + core/feature_registry.php, 2026-10-23).
$PRODUCT_COPY = [
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
$GENERIC_COPY = $PRODUCT_COPY['bms_shop'];   // a product we have no copy for borrows the neutral shop wording

// ── Live catalogue ───────────────────────────────────────────────────────────
$products = [];
if (!$closed) {
    try {
        foreach (listPublicOffers(null, true) as $prod) {
            $copy = $PRODUCT_COPY[$prod['product_key']] ?? array_merge($GENERIC_COPY, ['icon' => 'gen', 'desc' => (string)$prod['description']]);
            $plans = [];
            foreach ($prod['plans'] as $p) {
                $feats = [];
                if ($p['max_shops'] !== null) {
                    $feats[] = $p['max_shops'] === 1 ? $T['shop_1'] : sprintf($T['shops_n'], number_format($p['max_shops']));
                } elseif ($prod['product_key'] === 'bms_shop') {
                    $feats[] = $T['shops_unl'];
                }
                $feats[] = $p['max_storage_mb'] === null ? $T['storage_unl']
                    : sprintf($T['storage_gb'], rtrim(rtrim(number_format($p['max_storage_mb'] / 1000, 1), '0'), '.'));
                foreach ($p['items'] as $it) {
                    $feats[] = $it['label'] . ($it['value'] !== null && $it['value'] !== '' ? ': ' . $it['value'] : '');
                }
                $prices = [];
                foreach ($p['prices'] as $pr) {
                    $m = (int)$pr['months'];
                    $label = $sw
                        ? ($m % 12 === 0 ? 'Mwaka ' . ($m / 12) : ($m === 1 ? 'Mwezi 1' : 'Miezi ' . $m))
                        : $pr['term_label'];
                    $prices[$pr['term_key']] = ['label' => $label, 'months' => $m, 'price' => (int)$pr['price_tzs'],
                                                'per_month' => (int)$pr['per_month'], 'default' => (bool)$pr['is_default']];
                }
                $plans[] = ['key' => $p['plan_key'], 'name' => $p['name'], 'tagline' => (string)($p['tagline'] ?? ''),
                            'popular' => (bool)$p['is_popular'], 'feats' => $feats, 'prices' => $prices];
            }
            $products[] = ['key' => $prod['product_key'], 'name' => $prod['name'], 'copy' => $copy, 'plans' => $plans];
        }
    } catch (Throwable $e) {
        error_log('register.php offers: ' . $e->getMessage());
        $products = [];
    }
}

// Pre-selection from ?product=&plan=&term= (old login-popup links send plan+term).
$pickProduct = (string)($_GET['product'] ?? '');
$pickPlan    = (string)($_GET['plan'] ?? '');
$pickTerm    = (string)($_GET['term'] ?? '');
$planFound = false;
foreach ($products as $pr) foreach ($pr['plans'] as $pl) {
    if ($pl['key'] === $pickPlan) { $pickProduct = $pr['key']; $planFound = true; }
}
if (!$planFound) { $pickPlan = ''; $pickTerm = ''; }   // a forged or retired key falls back to the default
if (!in_array($pickProduct, array_column($products, 'key'), true)) $pickProduct = '';
if (count($products) === 1) $pickProduct = $products[0]['key'];

$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
// Eye button inside a password box: shows / hides what was typed.
$pwEye = static fn(string $for): string =>
    '<button type="button" class="pw-eye" data-pw="' . $for . '" aria-controls="' . $for . '" aria-pressed="false"'
    . ' aria-label="' . htmlspecialchars($T['pw_show']) . '" title="' . htmlspecialchars($T['pw_show']) . '">'
    . '<svg class="on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>'
    . '<svg class="off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/></svg>'
    . '</button>';
$langUrl = function (string $l) use ($pickProduct, $pickPlan, $pickTerm): string {
    return '?' . http_build_query(array_filter(['product' => $pickProduct, 'plan' => $pickPlan, 'term' => $pickTerm, 'lang' => $l]));
};
$langSwitcher = function () use ($sw, $langUrl): string {
    $globe = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><path d="M2.5 12h19M12 2.5c2.6 2.8 3.9 6 3.9 9.5s-1.3 6.7-3.9 9.5c-2.6-2.8-3.9-6-3.9-9.5s1.3-6.7 3.9-9.5z"/></svg>';
    $chev  = '<svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
    $item  = fn(string $l, string $flag, string $name) => '<a href="' . htmlspecialchars($langUrl($l)) . '" class="' . (($l === 'sw') === $sw ? 'active' : '') . '"><span>' . $flag . '</span> ' . $name
        . (($l === 'sw') === $sw ? '<span class="tick">✓</span>' : '') . '</a>';
    return '<div class="lang-switcher"><button type="button" class="lang-btn" aria-haspopup="true">' . $globe . ($sw ? 'SW' : 'EN') . $chev . '</button>'
        . '<div class="lang-menu">' . $item('en', '🇬🇧', 'English') . $item('sw', '🇹🇿', 'Kiswahili') . '</div></div>';
};
$icons = [
    'pos' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.5 12h12L22 7H6"/></svg>',
    'bms' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
    'gen' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M4 21V8l8-5 8 5v13"/><path d="M9 21v-6h6v6"/></svg>',
];
?>
<!DOCTYPE html>
<html lang="<?= $pageLang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($T['title']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    :root { --blue: #1d4ed8; --blue-dark: #1e40af; --ink: #0f172a; --muted: #475569; --line: #e5eaf3; --field: #b9c5d8; --tint: #f5f8ff; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #fff; color: var(--ink); font-family: 'Plus Jakarta Sans', system-ui, sans-serif; }
    .shell { display: flex; min-height: 100vh; }
    /* Left panel */
    .aside { width: 460px; flex-shrink: 0; background: var(--blue-dark); color: #fff; padding: 40px 48px 30px; display: flex; flex-direction: column; gap: 20px; min-height: 100vh; position: relative; }
    .aside::before { content: ''; position: absolute; width: 460px; height: 460px; border-radius: 50%; border: 70px solid rgba(255,255,255,.05); top: -200px; right: -230px; }
    .aside::after { content: ''; position: absolute; width: 300px; height: 300px; border-radius: 50%; background: rgba(255,255,255,.04); bottom: -130px; left: -120px; }
    .aside > * { position: relative; z-index: 1; }
    .brand { display: flex; align-items: center; gap: 12px; font-size: 20px; font-weight: 800; letter-spacing: -.3px; }
    .brand-ico { width: 46px; height: 46px; border-radius: 13px; background: #fff; color: var(--blue-dark); display: flex; align-items: center; justify-content: center; box-shadow: 0 6px 16px rgba(0,0,0,.15); }
    .brand-ico svg { width: 23px; height: 23px; }
    .aside h1 { margin: 10px 0 0; font-size: 36px; line-height: 1.12; font-weight: 800; letter-spacing: -1px; }
    .aside .lead-txt { margin: 0; font-size: 17px; line-height: 1.6; color: #e0e9ff; font-weight: 500; }
    .plan-card { background: #fff; color: var(--ink); border-radius: 16px; padding: 18px 20px; box-shadow: 0 12px 30px rgba(0,0,0,.18); }
    .plan-card .lbl { font-size: 12px; font-weight: 800; letter-spacing: 1px; color: var(--blue-dark); }
    .link-btn { background: none; border: 0; padding: 0; font: inherit; font-weight: 700; color: var(--blue); text-decoration: underline; cursor: pointer; }
    .demo { background: #fff; color: var(--ink); border-radius: 16px; padding: 14px 18px; box-shadow: 0 12px 30px rgba(0,0,0,.18); }
    .demo-bars { height: 64px; display: flex; align-items: flex-end; gap: 8px; margin: 10px 0; }
    .demo-bars span { flex: 1; border-radius: 6px 6px 2px 2px; background: #c3d4ff; height: 40%; transition: height .9s ease; }
    .demo-bars span:last-child { background: var(--blue-dark); }
    .demo-live { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; color: var(--blue-dark); }
    .demo-live i { width: 8px; height: 8px; border-radius: 50%; background: var(--blue-dark); animation: pulse 1.6s infinite; }
    @keyframes pulse { 50% { opacity: .25; } }
    .demo-toast { font-size: 13px; font-weight: 700; color: var(--blue-dark); background: #eef3ff; border-radius: 8px; padding: 7px 10px; transition: opacity .6s, transform .6s; }
    .demo-toast.hide { opacity: 0; transform: translateY(6px); }
    .benefits .title { font-size: 13px; font-weight: 800; color: #c7d7ff; letter-spacing: 1px; margin-bottom: 12px; }
    .benefits .row-b { display: flex; align-items: center; gap: 12px; font-size: 15px; font-weight: 600; margin-bottom: 11px; }
    .benefits .row-b b { width: 24px; height: 24px; flex-shrink: 0; border-radius: 50%; background: #fff; color: var(--blue-dark); display: flex; align-items: center; justify-content: center; font-size: 12px; }
    .benefits .etc { font-size: 15px; font-weight: 700; color: #c7d7ff; padding-left: 36px; }
    .aside-foot { font-size: 13px; color: #c7d7ff; border-top: 1px solid rgba(255,255,255,.22); padding-top: 16px; }
    /* Mobile header */
    .mobhead { display: none; background: var(--blue-dark); color: #fff; padding: 16px 16px 14px; }
    .mobhead .brand { font-size: 16px; }
    .mobhead .brand-ico { width: 34px; height: 34px; border-radius: 10px; background: rgba(255,255,255,.18); color: #fff; box-shadow: none; }
    .mobhead .brand-ico svg { width: 17px; height: 17px; }
    .mob-plan { margin-top: 12px; display: flex; justify-content: space-between; align-items: center; gap: 8px; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.3); border-radius: 10px; padding: 8px 12px; font-size: 13px; color: #e0e9ff; }
    .mob-plan strong { color: #fff; }
    .mob-plan .link-btn { color: #fff; }
    .mob-steps { margin-top: 12px; font-size: 12px; font-weight: 700; letter-spacing: .5px; }
    .mob-bars { display: grid; gap: 5px; margin-top: 7px; }
    .mob-bars span { height: 5px; border-radius: 3px; background: rgba(255,255,255,.28); }
    .mob-bars span.on { background: #fff; }
    /* Main */
    .main { flex: 1; min-width: 0; padding: 26px 56px 40px; }
    .topbar { display: flex; justify-content: flex-end; align-items: center; margin-bottom: 18px; }
    .lang-switcher { position: relative; }
    .lang-btn { background: #fff; border: 1px solid #dee2e6; border-radius: 20px; padding: 6px 14px; font-family: inherit; font-size: 13px; font-weight: 700; color: #334155; cursor: pointer; display: flex; align-items: center; gap: 6px; box-shadow: 0 1px 4px rgba(0,0,0,.07); transition: border-color .15s, color .15s; }
    .lang-btn:hover { border-color: var(--blue); color: var(--blue); }
    .lang-btn svg { width: 15px; height: 15px; }
    .lang-btn .chev { width: 11px; height: 11px; }
    .lang-menu { position: absolute; right: 0; top: calc(100% + 6px); background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; min-width: 160px; box-shadow: 0 6px 20px rgba(0,0,0,.12); overflow: hidden; z-index: 50; display: none; }
    .lang-menu.open { display: block; }
    .lang-menu a { display: flex; align-items: center; gap: 8px; padding: 10px 14px; font-size: 14px; color: #1e293b; text-decoration: none; }
    .lang-menu a:hover { background: #f4f8ff; color: var(--blue); }
    .lang-menu a.active { font-weight: 700; color: var(--blue); }
    .lang-menu a .tick { margin-left: auto; color: var(--blue); font-size: 12px; }
    .signin-line { text-align: center; font-size: 15px; color: var(--muted); margin: 28px auto 0; padding-top: 20px; border-top: 1px solid var(--line); max-width: 920px; }
    .signin-line a { color: var(--blue); font-weight: 700; text-decoration: none; }
    .signin-line a:hover { text-decoration: underline; }
    .wrap { max-width: 920px; margin: 0 auto; }
    .wrap.narrow { max-width: 700px; }
    .card-x { background: #fff; border: 1px solid var(--line); border-radius: 18px; box-shadow: 0 10px 34px rgba(15,23,42,.06); padding: 30px 38px; }
    .stepper { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 13px; font-weight: 600; padding-bottom: 16px; margin-bottom: 18px; border-bottom: 1px solid #eef1f6; }
    .stepper .dot { width: 26px; height: 26px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; border: 2px solid #cbd5e1; color: var(--muted); background: #fff; }
    .stepper .on .dot { background: var(--blue); border-color: var(--blue); color: #fff; }
    .stepper .done .dot { background: #dbe7ff; border-color: #dbe7ff; color: var(--blue); }
    .stepper .on, .stepper .done { color: var(--blue); }
    .stepper .todo { color: var(--muted); }
    .stepper .ln { width: 40px; height: 2px; background: #e2e8f0; }
    .stepper .ln.done { background: var(--blue); }
    h2.h { margin: 0 0 6px; font-size: 28px; font-weight: 800; letter-spacing: -.3px; }
    h2.hs { margin: 0 0 4px; font-size: 24px; font-weight: 800; }
    .sub-p { margin: 0 0 20px; font-size: 16px; color: #334155; font-weight: 500; line-height: 1.55; }
    .form-label { font-size: 14px; font-weight: 700; color: var(--ink); margin-bottom: 6px; }
    .form-label .req { color: var(--blue); }
    .form-label .opt { font-weight: 400; color: var(--muted); }
    .form-control, .form-select { font-family: inherit; font-size: 15px; padding: 12px 14px; border: 1px solid var(--field); border-radius: 10px; min-height: 46px; color: var(--ink); }
    .form-control:focus, .form-select:focus { border-color: var(--blue); box-shadow: 0 0 0 4px rgba(29,78,216,.12); }
    .hint { font-size: 12px; font-weight: 600; color: var(--muted); margin-top: 6px; }
    .hint.ok { color: var(--blue); }
    .sfx { background: var(--tint); color: var(--muted); font-size: 13px; border-color: var(--field); }
    .btn-blue { background: var(--blue); color: #fff; border: 0; font-weight: 700; font-size: 15px; padding: 14px 24px; border-radius: 10px; min-height: 50px; }
    .btn-blue:hover, .btn-blue:focus { background: var(--blue-dark); color: #fff; }
    .btn-line { background: #fff; color: var(--blue); border: 1px solid #c7d6f7; font-weight: 700; font-size: 15px; padding: 14px 22px; border-radius: 10px; min-height: 50px; }
    .btn-line:hover { background: var(--tint); color: var(--blue); }
    .note { background: var(--tint); border: 1px solid #dbe5fb; border-radius: 12px; padding: 14px 18px; font-size: 14px; color: #1e3a8a; }
    .err { border: 1px solid var(--blue); background: var(--tint); border-radius: 10px; padding: 10px 14px; font-size: 14px; margin-bottom: 14px; }
    /* Field errors: the exact problem, under the field it belongs to */
    .err.bad { border-color: #fca5a5; background: #fef2f2; color: #991b1b; font-weight: 600; }
    .form-control.is-bad, .form-select.is-bad { border-color: #dc2626; box-shadow: 0 0 0 3px rgba(220,38,38,.12); }
    .ferr { font-size: 13px; font-weight: 600; color: #b91c1c; margin-top: 6px; line-height: 1.4; }
    label.is-bad > span { color: #b91c1c; }
    label.is-bad > input[type=checkbox] { outline: 2px solid #dc2626; outline-offset: 2px; }
    /* Show / hide password */
    .pw-wrap { position: relative; }
    .pw-wrap .form-control { padding-right: 50px; }
    .pw-eye { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); width: 38px; height: 38px; border: 0; border-radius: 8px; background: transparent; color: #64748b; display: flex; align-items: center; justify-content: center; }
    .pw-eye:hover, .pw-eye:focus-visible { color: var(--blue); background: var(--tint); }
    .pw-eye svg { width: 20px; height: 20px; }
    .pw-eye .off, .pw-eye[aria-pressed="true"] .on { display: none; }
    .pw-eye[aria-pressed="true"] .off { display: block; }
    /* Product + plan cards */
    .pgrid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; }
    .pcard { background: #fff; border: 1px solid #dbe3f0; border-radius: 18px; padding: 24px; display: flex; flex-direction: column; gap: 14px; box-shadow: 0 6px 20px rgba(15,23,42,.05); }
    .pcard .ico { width: 50px; height: 50px; border-radius: 14px; background: #eef3ff; color: var(--blue); display: flex; align-items: center; justify-content: center; }
    .pcard .ico svg { width: 24px; height: 24px; }
    .chip { font-size: 12px; font-weight: 700; color: var(--blue); background: #eef3ff; border-radius: 20px; padding: 5px 12px; }
    .feat { font-size: 14px; color: #334155; margin-bottom: 8px; }
    .feat b { color: var(--blue); }
    .terms { display: inline-flex; flex-wrap: wrap; gap: 2px; background: #eef3ff; border-radius: 10px; padding: 4px; margin-bottom: 18px; }
    .terms button { font-family: inherit; font-size: 13px; font-weight: 600; padding: 9px 16px; border: 0; border-radius: 8px; background: transparent; color: #1e3a8a; min-height: 40px; }
    .terms button.on { font-weight: 700; background: var(--blue); color: #fff; }
    .plcard { text-align: left; font-family: inherit; color: var(--ink); background: #fff; border: 1px solid #dbe3f0; border-radius: 18px; padding: 25px; display: flex; flex-direction: column; gap: 10px; position: relative; width: 100%; }
    .plcard.sel { background: var(--tint); border: 2px solid var(--blue); padding: 24px; box-shadow: 0 10px 28px rgba(29,78,216,.13); }
    .plcard[disabled] { opacity: .5; }
    .plcard .radio { width: 22px; height: 22px; border-radius: 50%; border: 2px solid #cbd5e1; background: #fff; }
    .plcard.sel .radio { border: 7px solid var(--blue); }
    .plcard .pop { position: absolute; top: -12px; left: 22px; font-size: 12px; font-weight: 700; color: #fff; background: var(--blue); border-radius: 20px; padding: 4px 12px; }
    .price { font-size: 32px; font-weight: 800; }
    /* Password checks */
    .checks span { font-size: 13px; font-weight: 600; color: #64748b; margin-right: 18px; }
    .checks span.ok { color: var(--blue); }
    /* Creating + done */
    .center-box { max-width: 560px; margin: 40px auto 0; }
    .cstep { display: flex; align-items: center; gap: 12px; font-size: 15px; color: #64748b; margin-bottom: 14px; }
    .cstep .n { width: 28px; height: 28px; flex-shrink: 0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 800; border: 2px solid #e2e8f0; }
    .cstep.active { color: var(--ink); font-weight: 700; }
    .cstep.active .n { border-color: var(--blue); color: var(--blue); }
    .cstep.done { color: var(--ink); }
    .cstep.done .n { background: var(--blue); border-color: var(--blue); color: #fff; }
    .progress-x { height: 8px; background: #eef3ff; border-radius: 4px; overflow: hidden; margin: 18px 0 22px; }
    .progress-x div { height: 100%; width: 5%; background: var(--blue); border-radius: 4px; transition: width .6s; }
    .big-check { width: 70px; height: 70px; border-radius: 50%; background: var(--blue); color: #fff; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; }
    .drow { display: flex; justify-content: space-between; gap: 16px; padding: 12px 0; border-bottom: 1px solid #e3eafb; font-size: 14px; }
    .drow:last-child { border-bottom: 0; }
    .drow span { color: var(--muted); }
    .drow strong { text-align: right; word-break: break-all; }
    .hp-field { position: absolute; left: -9999px; top: -9999px; height: 0; overflow: hidden; }
    .d-none-i { display: none !important; }
    @media (max-width: 991.98px) {
        .shell { display: block; }
        .aside { display: none; }
        .mobhead { display: block; }
        .topbar { display: none; }
        .main { padding: 18px 16px 28px; }
        .card-x { border: 0; box-shadow: none; padding: 0; }
        .card-x .stepper { display: none; }
        .pgrid { grid-template-columns: minmax(0, 1fr); }
        h2.h { font-size: 23px; } h2.hs { font-size: 21px; }
        .form-control, .form-select { font-size: 16px; }
        .drow { flex-direction: column; gap: 2px; }
        .drow strong { text-align: left; }
        .terms { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); width: 100%; }
        .terms button { padding: 9px 4px; }
        .center-box { margin-top: 10px; }
    }
</style>
</head>
<body>
<div class="shell">

    <aside class="aside" id="aside">
        <div class="brand"><div class="brand-ico" id="brandIco"></div><span id="brandName"></span></div>
        <div>
            <h1 id="asideHead"></h1>
            <p class="lead-txt mt-3" id="asideSub"></p>
        </div>
        <div class="plan-card d-none-i" id="asidePlan">
            <div class="d-flex justify-content-between align-items-center">
                <span class="lbl"><?= htmlspecialchars($T['your_plan']) ?></span>
                <button type="button" class="link-btn" data-go="plan"><?= htmlspecialchars($T['change']) ?></button>
            </div>
            <div class="fw-bold mt-1" style="font-size:18px" id="asidePlanLine"></div>
            <div style="font-size:14px;color:#334155;font-weight:500" id="asidePlanPrice"></div>
        </div>
        <div class="demo" aria-hidden="true">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div style="font-size:12px;font-weight:700;color:var(--muted)"><?= htmlspecialchars($T['example']) ?> · <span id="demoTitle"></span></div>
                    <div style="font-size:22px;font-weight:800;letter-spacing:-.3px"><span id="demoValue"></span> <span style="font-size:13px;font-weight:600;color:var(--muted)">TZS</span></div>
                </div>
                <span class="demo-live"><i></i><?= htmlspecialchars($T['live']) ?></span>
            </div>
            <div class="demo-bars" id="demoBars"><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
            <div class="demo-toast" id="demoToast"></div>
        </div>
        <div style="flex-grow:1"></div>
        <div class="benefits">
            <div class="title" id="asideListTitle"></div>
            <div id="asideList"></div>
            <div class="etc d-none-i" id="asideEtc"><?= htmlspecialchars($T['etc']) ?></div>
        </div>
        <div class="aside-foot">© <?= date('Y') ?> BJP Technologies (T) Ltd</div>
    </aside>

    <div class="mobhead" id="mobhead">
        <div class="d-flex justify-content-between align-items-center">
            <div class="brand"><div class="brand-ico" id="mBrandIco"></div><span id="mBrandName"></span></div>
            <?= $langSwitcher() ?>
        </div>
        <div class="mob-plan d-none-i" id="mobPlan">
            <span><strong id="mobPlanLine"></strong><br><span id="mobPlanPrice"></span></span>
            <button type="button" class="link-btn" data-go="plan"><?= htmlspecialchars($T['change']) ?></button>
        </div>
        <div class="mob-steps d-none-i" id="mobSteps">
            <div id="mobStepText"></div>
            <div class="mob-bars" id="mobBars"></div>
        </div>
    </div>

    <main class="main">
        <div class="topbar"><?= $langSwitcher() ?></div>

<?php if ($closed): ?>
        <div class="wrap narrow"><div class="card-x text-center"><?= safe_output($closed, '') ?></div></div>
<?php else: ?>

        <!-- Step: product -->
        <section class="wrap d-none-i" id="stepProduct">
            <div class="stepper" data-stepper></div>
            <h2 class="h"><?= htmlspecialchars($T['product_h']) ?></h2>
            <p class="sub-p"><?= htmlspecialchars($T['product_sub']) ?></p>
            <div class="pgrid" id="productCards"></div>
            <p class="mt-4 mb-3" style="font-size:14px;color:var(--muted)"><?= htmlspecialchars($T['not_sure']) ?></p>
            <a href="/login" class="btn btn-line"><?= htmlspecialchars($T['back_login']) ?></a>
        </section>

        <!-- Step: plan -->
        <section class="wrap d-none-i" id="stepPlan">
            <div class="stepper" data-stepper></div>
            <h2 class="h" id="planHead"></h2>
            <p class="sub-p"><?= htmlspecialchars($T['plan_sub']) ?></p>
            <div class="terms" id="termBtns" role="group"></div>
            <div class="pgrid" id="planCards"></div>
            <div class="note mt-4" id="planNote"></div>
            <div class="d-flex justify-content-between gap-3 mt-4">
                <button type="button" class="btn btn-line" id="planBack"><?= htmlspecialchars($T['back']) ?></button>
                <button type="button" class="btn btn-blue" id="planNext"></button>
            </div>
        </section>

        <form id="registerForm" autocomplete="on" novalidate enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="product_key" id="f_product">
            <input type="hidden" name="plan_key" id="f_plan">
            <input type="hidden" name="plan_term" id="f_term">
            <div class="hp-field" aria-hidden="true">
                <label for="website">Website</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <!-- Step: company -->
            <section class="wrap narrow d-none-i" id="stepCompany">
                <div class="card-x">
                    <div class="stepper" data-stepper></div>
                    <h2 class="hs" id="coHead"></h2>
                    <p class="sub-p" id="coSub"></p>
                    <div class="err d-none-i" id="errCompany"></div>
                    <div class="mb-3">
                        <label for="company_name" class="form-label"><span id="nameLabel"></span> <span class="req">*</span></label>
                        <input type="text" class="form-control" id="company_name" name="company_name" maxlength="191" autocomplete="organization" required>
                    </div>
                    <div class="mb-3">
                        <label for="subdomain" class="form-label"><?= htmlspecialchars($T['web_address']) ?> <span class="req">*</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="subdomain" name="subdomain" maxlength="32" autocomplete="off" required>
                            <span class="input-group-text sfx">.<?= htmlspecialchars($baseDom) ?></span>
                        </div>
                        <div class="hint" id="subHint"><?= htmlspecialchars($T['sub_hint']) ?></div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="country" class="form-label"><?= htmlspecialchars($T['country']) ?> <span class="req">*</span></label>
                            <select class="form-select" id="country" name="country" required>
                                <?php // Supported countries (core/currency.php). Tanzania first = the default; the
                                      // country chosen sets the new company's currency (TZS/KES/BIF/RWF/SSP).
                                foreach (bmsRegistrationCountries() as $__ctry): ?>
                                <option value="<?= htmlspecialchars($__ctry) ?>"<?= $__ctry === 'Tanzania' ? ' selected' : '' ?>><?= htmlspecialchars($__ctry) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="business_type" class="form-label" id="typeLabel"></label>
                            <input type="text" class="form-control" id="business_type" name="business_type" maxlength="60">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="company_physical_address" class="form-label"><?= htmlspecialchars($T['phys']) ?> <span class="opt"><?= htmlspecialchars($T['optional']) ?></span></label>
                            <input type="text" class="form-control" id="company_physical_address" name="company_physical_address" maxlength="255" placeholder="<?= htmlspecialchars($T['phys_ph']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="company_postal_address" class="form-label"><?= htmlspecialchars($T['postal']) ?> <span class="opt"><?= htmlspecialchars($T['optional']) ?></span></label>
                            <input type="text" class="form-control" id="company_postal_address" name="company_postal_address" maxlength="255" placeholder="<?= htmlspecialchars($T['postal_ph']) ?>">
                        </div>
                    </div>
                    <label class="d-flex align-items-center gap-3 mb-4 p-3" style="border:2px dashed #c7d6f7;border-radius:12px;background:#fbfcff;cursor:pointer">
                        <span style="width:44px;height:44px;flex-shrink:0;border-radius:10px;background:#eef3ff;display:flex;align-items:center;justify-content:center;overflow:hidden">
                            <img id="logoPreview" alt="" class="d-none-i" style="max-width:100%;max-height:100%">
                            <svg id="logoIcon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#1d4ed8" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 16V4"/><path d="M7 9l5-5 5 5"/><path d="M4 20h16"/></svg>
                        </span>
                        <span style="font-size:13px;color:var(--muted)"><strong style="color:var(--blue)"><?= htmlspecialchars($T['logo']) ?></strong> <?= htmlspecialchars($T['optional']) ?> — <span id="logoFor"></span>. <?= htmlspecialchars($T['logo_hint']) ?></span>
                        <input type="file" id="company_logo" name="company_logo" accept="image/png,image/jpeg,image/gif" class="d-none">
                    </label>
                    <div class="d-flex gap-3">
                        <button type="button" class="btn btn-line" id="companyBack"><?= htmlspecialchars($T['back']) ?></button>
                        <button type="button" class="btn btn-blue flex-grow-1" id="companyNext"><?= htmlspecialchars($T['continue']) ?></button>
                    </div>
                </div>
            </section>

            <!-- Step: admin -->
            <section class="wrap narrow d-none-i" id="stepAdmin">
                <div class="card-x">
                    <div class="stepper" data-stepper></div>
                    <h2 class="hs"><?= htmlspecialchars($T['admin_h']) ?></h2>
                    <p class="sub-p" id="adminSub"></p>
                    <div class="err d-none-i" id="errAdmin"></div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label for="owner_first_name" class="form-label"><?= htmlspecialchars($T['first_name']) ?> <span class="req">*</span></label>
                            <input type="text" class="form-control" id="owner_first_name" name="owner_first_name" maxlength="100" autocomplete="given-name" required>
                        </div>
                        <div class="col-6">
                            <label for="owner_last_name" class="form-label"><?= htmlspecialchars($T['last_name']) ?> <span class="req">*</span></label>
                            <input type="text" class="form-control" id="owner_last_name" name="owner_last_name" maxlength="100" autocomplete="family-name" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="owner_username" class="form-label"><?= htmlspecialchars($T['username']) ?> <span class="req">*</span></label>
                        <input type="text" class="form-control" id="owner_username" name="owner_username" maxlength="50" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="jane.mwangi" required>
                        <div class="hint" id="userHint"><?= htmlspecialchars($T['user_hint']) ?></div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="owner_phone" class="form-label"><?= htmlspecialchars($T['phone']) ?> <span class="req">*</span></label>
                            <input type="tel" class="form-control" id="owner_phone" name="owner_phone" maxlength="20" autocomplete="tel" placeholder="<?= htmlspecialchars($T['phone_ph']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="owner_email" class="form-label"><?= htmlspecialchars($T['email']) ?> <span class="req">*</span></label>
                            <input type="email" class="form-control" id="owner_email" name="owner_email" maxlength="191" autocomplete="email" required>
                            <div class="hint"><?= htmlspecialchars($T['email_hint']) ?></div>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="owner_password" class="form-label"><?= htmlspecialchars($T['password']) ?> <span class="req">*</span></label>
                            <div class="pw-wrap">
                                <input type="password" class="form-control" id="owner_password" name="owner_password" autocomplete="new-password" placeholder="<?= htmlspecialchars($T['password_ph']) ?>" required>
                                <?= $pwEye('owner_password') ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="owner_password_confirm" class="form-label"><?= htmlspecialchars($T['confirm']) ?> <span class="req">*</span></label>
                            <div class="pw-wrap">
                                <input type="password" class="form-control" id="owner_password_confirm" name="owner_password_confirm" autocomplete="new-password" placeholder="<?= htmlspecialchars($T['confirm_ph']) ?>" required>
                                <?= $pwEye('owner_password_confirm') ?>
                            </div>
                        </div>
                    </div>
                    <div class="checks mb-3" id="pwChecks">
                        <span data-c="len"><b class="ck" aria-hidden="true">○</b> <?= htmlspecialchars($T['chk_len']) ?></span>
                        <span data-c="letter"><b class="ck" aria-hidden="true">○</b> <?= htmlspecialchars($T['chk_letter']) ?></span>
                        <span data-c="num"><b class="ck" aria-hidden="true">○</b> <?= htmlspecialchars($T['chk_num']) ?></span>
                        <span data-c="match"><b class="ck" aria-hidden="true">○</b> <?= htmlspecialchars($T['chk_match']) ?></span>
                    </div>
                    <label class="d-flex align-items-start gap-2 mb-4" style="font-size:14px;color:#334155">
                        <input type="checkbox" id="accept_terms" name="accept_terms" value="1" style="width:18px;height:18px;margin-top:2px;flex-shrink:0">
                        <span><?= $T['terms'] ?></span>
                    </label>
                    <div class="d-flex gap-3">
                        <button type="button" class="btn btn-line" id="adminBack"><?= htmlspecialchars($T['back']) ?></button>
                        <button type="submit" class="btn btn-blue flex-grow-1" id="btnSubmit"><?= htmlspecialchars($T['create']) ?></button>
                    </div>
                </div>
            </section>
        </form>

        <!-- Setting up -->
        <section class="center-box d-none-i" id="stepCreating">
            <div class="card-x">
                <h2 class="hs" id="creatingHead"></h2>
                <p class="sub-p mb-0"><?= htmlspecialchars($T['creating_sub']) ?></p>
                <div class="progress-x"><div id="progressBar"></div></div>
                <div id="cSteps"></div>
            </div>
        </section>

        <!-- Ready (instant products) -->
        <section class="center-box d-none-i" id="stepDone">
            <div class="card-x">
                <div class="text-center">
                    <div class="big-check"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5 9-10"/></svg></div>
                    <h2 class="hs" id="doneHead"></h2>
                    <p class="sub-p mb-3" id="doneSub"></p>
                </div>
                <div style="background:#f8faff;border:1px solid #e3eafb;border-radius:14px;padding:4px 18px">
                    <div class="drow"><span><?= htmlspecialchars($T['wa_label']) ?></span><strong id="doneAddr"></strong></div>
                    <div class="drow"><span><?= htmlspecialchars($T['username']) ?></span><strong id="doneUser"></strong></div>
                    <div class="drow"><span><?= htmlspecialchars($T['password']) ?></span><strong><?= htmlspecialchars($T['pw_note']) ?></strong></div>
                    <div class="drow d-none-i" id="donePlanRow"><span><?= htmlspecialchars($T['plan_label']) ?></span><strong id="donePlan"></strong></div>
                </div>
                <p class="text-center mt-3 mb-3" style="font-size:13px;color:var(--muted)" id="doneSent"></p>
                <a class="btn btn-blue w-100" id="doneSignIn" href="/login"><?= htmlspecialchars($T['sign_in_now']) ?></a>
            </div>
        </section>

        <!-- Received (Smart BMS waits for approval) -->
        <section class="center-box d-none-i" id="stepPending">
            <div class="card-x text-center">
                <div class="big-check"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div>
                <h2 class="hs"><?= htmlspecialchars($T['pending_h']) ?></h2>
                <p class="sub-p"><?= htmlspecialchars($T['pending_sub']) ?></p>
                <div style="background:#f8faff;border:1px solid #e3eafb;border-radius:14px;padding:4px 18px;text-align:left" class="mb-4">
                    <div class="drow"><span><?= htmlspecialchars($T['wa_label']) ?></span><strong id="pendAddr"></strong></div>
                    <div class="drow"><span><?= htmlspecialchars($T['username']) ?></span><strong id="pendUser"></strong></div>
                </div>
                <a class="btn btn-blue w-100" href="/login"><?= htmlspecialchars($T['pending_ok']) ?></a>
            </div>
        </section>
<?php endif; ?>
        <p class="signin-line" id="signinLine"><?= htmlspecialchars($T['have_account']) ?> <a href="/login"><?= htmlspecialchars($T['sign_in']) ?></a></p>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script>
(function () {
    const T = <?= json_encode($T, $jsonFlags) ?>;
    const PRODUCTS = <?= json_encode($products, $jsonFlags) ?>;
    const GENERIC = <?= json_encode($GENERIC_COPY, $jsonFlags) ?>;
    const ICONS = <?= json_encode($icons, $jsonFlags) ?>;
    const BASE = <?= json_encode($baseDom, $jsonFlags) ?>;
    const TRIAL_DAYS = <?= (int)$trialDays ?>;
    const TRIAL_END = <?= json_encode($trialEnd, $jsonFlags) ?>;
    const CLOSED = <?= $closed ? 'true' : 'false' ?>;

    const fmt = n => Number(n).toLocaleString('en-US');
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const fill = (tpl, ...a) => { let i = 0; return tpl.replace(/%[sd]/g, () => a[i++]); };

    // Which steps exist depends on the live catalogue.
    const hasProductStep = PRODUCTS.length > 1;
    const hasPlanStep = PRODUCTS.length >= 1;
    const S = {
        screen: 'product',
        product: <?= json_encode($pickProduct, $jsonFlags) ?>,
        plan: <?= json_encode($pickPlan, $jsonFlags) ?>,
        term: <?= json_encode($pickTerm, $jsonFlags) ?>,
        subTouched: false, subOk: false
    };

    const prod = () => PRODUCTS.find(p => p.key === S.product) || null;
    const copy = () => (prod() ? prod().copy : GENERIC);
    const plan = () => { const p = prod(); return p ? (p.plans.find(x => x.key === S.plan) || null) : null; };
    const price = () => { const p = plan(); return p && p.prices[S.term] ? p.prices[S.term] : null; };

    function defaultPlanFor(p) {
        const pl = p.plans.find(x => x.popular) || p.plans[0];
        S.plan = pl.key;
        const t = Object.keys(pl.prices).find(k => pl.prices[k].default) || Object.keys(pl.prices)[0];
        S.term = t;
    }
    function ensureSelection() {
        const p = prod();
        if (!p) return;
        if (!plan()) defaultPlanFor(p);
        if (!price()) {
            const pl = plan();
            S.term = Object.keys(pl.prices).find(k => pl.prices[k].default) || Object.keys(pl.prices)[0];
        }
    }

    function flowSteps() {
        const st = [];
        if (hasProductStep) st.push(['product', T.step_product]);
        if (hasPlanStep) st.push(['plan', T.step_plan]);
        st.push(['company', copy().step]);
        st.push(['admin', T.step_admin]);
        return st;
    }

    // ── Left panel / mobile header ───────────────────────────────────────────
    function renderAside() {
        const generic = S.screen === 'product' || !prod();
        const c = copy();
        const icon = S.screen === 'product' ? ICONS.gen : (ICONS[c.icon] || ICONS.gen);
        const name = S.screen === 'product' ? 'BJP Technologies' : (prod() ? prod().name : 'Smart POS');
        $('#brandIco, #mBrandIco').html(icon);
        $('#brandName, #mBrandName').text(name);
        const done = S.screen === 'done';
        $('#asideHead').text(S.screen === 'product' ? T.gen_head : done ? fill(T.welcome, name) : c.head);
        $('#asideSub').text(S.screen === 'product' ? T.gen_sub : done ? T.welcome_sub : c.sub);
        const list = S.screen === 'product' ? T.gen_list : done ? c.first : c.benefits;
        $('#asideListTitle').text(S.screen === 'product' ? T.gen_list_title : done ? T.first_steps : T.what_you_get);
        $('#asideList').html(list.map(b => '<div class="row-b"><b>✓</b>' + esc(b) + '</div>').join(''));
        $('#asideEtc').toggleClass('d-none-i', S.screen === 'product' || done);

        const showPlan = ['company', 'admin', 'done'].includes(S.screen) && plan() && price();
        if (showPlan) {
            const line = prod().name + ' · ' + plan().name + ' · ' + price().label;
            const pr = fmt(price().price) + ' TZS · ' + fill(T.free_days, TRIAL_DAYS);
            $('#asidePlanLine, #mobPlanLine').text(line);
            $('#asidePlanPrice, #mobPlanPrice').text(pr);
        }
        $('#asidePlan, #mobPlan').toggleClass('d-none-i', !showPlan);

        const steps = flowSteps();
        const idx = steps.findIndex(s => s[0] === S.screen);
        $('#mobSteps').toggleClass('d-none-i', idx < 0);
        if (idx >= 0) {
            $('#mobStepText').text(fill(T.step_of, idx + 1, steps.length) + ' · ' + steps[idx][1].toUpperCase());
            $('#mobBars').css('grid-template-columns', 'repeat(' + steps.length + ', minmax(0,1fr))')
                .html(steps.map((s, i) => '<span class="' + (i <= idx ? 'on' : '') + '"></span>').join(''));
        }
        $('[data-stepper]').html(steps.map((s, i) => {
            const st = i < idx ? 'done' : (i === idx ? 'on' : 'todo');
            return '<span class="d-inline-flex align-items-center gap-2 ' + st + '"><span class="dot">' + (st === 'done' ? '✓' : (i + 1)) + '</span>' + esc(s[1]) + '</span>'
                + (i < steps.length - 1 ? '<span class="ln ' + (i < idx ? 'done' : '') + '"></span>' : '');
        }).join(''));
        demoCopy();
    }

    // ── Animated example dashboard ───────────────────────────────────────────
    const SEQ = [[40, 62, 48, 75, 58, 88, 70], [55, 45, 70, 60, 82, 66, 92], [35, 58, 66, 50, 72, 80, 97]];
    let tick = 0;
    function demoCopy() {
        const c = (S.screen === 'product' || !prod()) ? GENERIC : copy();
        $('#demoTitle').text(c.demo_t);
        $('#demoValue').text(c.demo_v[tick % 3]);
        $('#demoToast').text('✓ ' + c.demo_n[tick % 3]);
    }
    function demoTick() {
        const h = SEQ[tick % 3];
        $('#demoBars span').each(function (i) { this.style.height = h[i] + '%'; });
        $('#demoToast').toggleClass('hide', tick % 3 === 0);
        demoCopy();
        tick++;
    }
    demoTick();
    setInterval(demoTick, 1800);

    // ── Screens ──────────────────────────────────────────────────────────────
    function show(screen) {
        S.screen = screen;
        ['Product', 'Plan', 'Company', 'Admin', 'Creating', 'Done', 'Pending'].forEach(n =>
            $('#step' + n).toggleClass('d-none-i', n.toLowerCase() !== screen));
        if (screen === 'plan') renderPlans();
        if (screen === 'company') renderCompany();
        if (screen === 'admin') $('#adminSub').text(copy().admin_sub);
        renderAside();
        syncUrl();
        window.scrollTo(0, 0);
    }
    // The address bar stays a clean /register; only the language links carry the picks so a switch keeps them.
    function syncUrl() {
        if (location.search) history.replaceState(null, '', location.pathname);
        document.querySelectorAll('.lang-menu a').forEach(a => {
            const u = new URLSearchParams(a.getAttribute('href').slice(1));
            [['product', S.product], ['plan', S.plan], ['term', S.term]].forEach(([k, v]) => v ? u.set(k, v) : u.delete(k));
            a.setAttribute('href', '?' + u.toString());
        });
        $('#signinLine').toggleClass('d-none-i', ['creating', 'done', 'pending'].includes(S.screen));
    }
    $(document).on('click', '.lang-btn', function (e) {
        e.stopPropagation();
        const m = $(this).next('.lang-menu');
        $('.lang-menu').not(m).removeClass('open');
        m.toggleClass('open');
    });
    $(document).on('click', () => $('.lang-menu').removeClass('open'));

    function renderProducts() {
        $('#productCards').html(PRODUCTS.map(p => {
            const c = p.copy;
            const min = Math.min(...p.plans.flatMap(pl => Object.values(pl.prices).map(x => x.per_month)));
            return '<div class="pcard">'
                + '<div class="d-flex justify-content-between align-items-start"><div class="ico">' + (ICONS[c.icon] || ICONS.gen) + '</div><span class="chip">' + esc(c.tag) + '</span></div>'
                + '<div><div style="font-size:23px;font-weight:800">' + esc(p.name) + '</div><div style="font-size:15px;color:var(--muted);margin-top:4px">' + esc(c.desc) + '</div></div>'
                + '<div>' + c.card.map(f => '<div class="feat"><b>✓</b> ' + esc(f) + '</div>').join('') + '</div>'
                + '<div style="flex-grow:1"></div>'
                + (isFinite(min) ? '<div style="font-size:14px;color:var(--muted)">' + esc(T.from) + ' <strong style="font-size:20px;color:var(--ink)">' + fmt(min) + ' TZS</strong> ' + esc(T.per_month) + '</div>' : '')
                + '<button type="button" class="btn btn-blue w-100" data-product="' + esc(p.key) + '">' + esc(fill(T.choose, p.name)) + '</button>'
                + '</div>';
        }).join(''));
    }
    $(document).on('click', '[data-product]', function () {
        S.product = this.getAttribute('data-product');
        S.plan = ''; S.term = '';
        ensureSelection();
        show('plan');
    });

    function renderPlans() {
        ensureSelection();
        const p = prod();
        $('#planHead').text(fill(T.plan_h, p.name));
        const terms = {};
        p.plans.forEach(pl => Object.keys(pl.prices).forEach(k => { terms[k] = pl.prices[k]; }));
        const keys = Object.keys(terms).sort((a, b) => terms[a].months - terms[b].months);
        $('#termBtns').toggleClass('d-none-i', keys.length < 2).html(keys.map(k =>
            '<button type="button" class="' + (k === S.term ? 'on' : '') + '" data-term="' + esc(k) + '">' + esc(terms[k].label) + '</button>').join(''));
        $('#planCards').html(p.plans.map(pl => {
            const pr = pl.prices[S.term];
            const sel = pl.key === S.plan;
            return '<button type="button" class="plcard ' + (sel ? 'sel' : '') + '" data-plan="' + esc(pl.key) + '"' + (pr ? '' : ' disabled') + '>'
                + (pl.popular ? '<span class="pop">' + esc(T.popular) + '</span>' : '')
                + '<span class="d-flex justify-content-between align-items-center"><span style="font-size:21px;font-weight:800">' + esc(pl.name) + '</span><span class="radio"></span></span>'
                + (pl.tagline ? '<span style="font-size:14px;color:var(--muted)">' + esc(pl.tagline) + '</span>' : '')
                + (pr ? '<span><span class="price">' + fmt(pr.price) + '</span> <span style="font-size:14px;color:var(--muted)">TZS / ' + esc(pr.label) + '</span></span>'
                      : '<span style="font-size:14px;color:var(--muted)">' + esc(T.not_term) + '</span>')
                + '<span style="display:block;height:1px;background:#e5eaf3"></span>'
                + pl.feats.map(f => '<span class="feat d-block mb-1"><b>✓</b> ' + esc(f) + '</span>').join('')
                + '</button>';
        }).join(''));
        const pl = plan(), pr = price();
        $('#planNote').html(fill(T.pay_today, TRIAL_DAYS, esc(pl.name), fmt(pr.price) + ' TZS / ' + esc(pr.label)));
        $('#planNext').text(fill(T.continue_with, pl.name));
        $('#planBack').toggleClass('invisible', !hasProductStep);
    }
    $(document).on('click', '[data-term]', function () {
        S.term = this.getAttribute('data-term');
        if (!price()) { const ok = prod().plans.find(p => p.prices[S.term]); if (ok) S.plan = ok.key; }
        renderPlans(); renderAside(); syncUrl();
    });
    $(document).on('click', '[data-plan]', function () { S.plan = this.getAttribute('data-plan'); renderPlans(); renderAside(); syncUrl(); });
    $(document).on('click', '[data-go="plan"]', () => show(hasPlanStep ? 'plan' : 'company'));
    $('#planBack').on('click', () => show('product'));
    $('#planNext').on('click', () => show('company'));

    function renderCompany() {
        const c = copy();
        $('#coHead').text(c.co_h);
        $('#coSub').text(c.co_sub);
        $('#nameLabel').text(c.name_l);
        $('#typeLabel').text(c.type_l);
        $('#business_type').attr('placeholder', c.type_ph);
        $('#logoFor').text(c.logo_for);
        $('#companyBack').toggleClass('invisible', !hasPlanStep);
    }

    // ── Company fields ───────────────────────────────────────────────────────
    let subTimer = null;
    function checkSub() {
        const v = $('#subdomain').val();
        const $h = $('#subHint');
        S.subOk = false;
        if (!/^[a-z0-9][a-z0-9-]{2,31}$/.test(v)) { $h.removeClass('ok').text(T.sub_hint); return; }
        $h.removeClass('ok').text(T.sub_checking);
        $.getJSON('/ajax/check_subdomain_availability.php', { subdomain: v }).done(function (res) {
            if ($('#subdomain').val() !== v) return;
            S.subOk = !!res.available;
            $h.toggleClass('ok', S.subOk).text(S.subOk ? fill(T.sub_ok, v + '.' + BASE) : (res.message || T.sub_hint));
        }).fail(() => $h.removeClass('ok').text(T.sub_hint));
    }
    $('#company_name').on('input', function () {
        if (S.subTouched) return;
        $('#subdomain').val($(this).val().toLowerCase().replace(/[^a-z0-9]+/g, '').slice(0, 32));
        clearTimeout(subTimer); subTimer = setTimeout(checkSub, 400);
    });
    $('#subdomain').on('input', function () {
        S.subTouched = true;
        const clean = $(this).val().toLowerCase().replace(/[^a-z0-9-]/g, '');
        if (clean !== $(this).val()) $(this).val(clean);
        clearTimeout(subTimer); subTimer = setTimeout(checkSub, 350);
    });
    $('#company_logo').on('change', function () {
        const f = this.files && this.files[0];
        if (!f) { $('#logoPreview').addClass('d-none-i'); $('#logoIcon').show(); return; }
        const rd = new FileReader();
        rd.onload = e => { $('#logoPreview').attr('src', e.target.result).removeClass('d-none-i'); $('#logoIcon').hide(); };
        rd.readAsDataURL(f);
    });
    $('#companyBack').on('click', () => show('plan'));

    // ── Field errors: the exact problem under each field, plus a short summary ──
    function errAnchor(id) {
        const $f = $('#' + id);
        if (id === 'accept_terms') return $f.closest('label');
        const $w = $f.closest('.input-group, .pw-wrap');
        return $w.length ? $w : $f;
    }
    function setErr(id, msg) {
        const $f = $('#' + id), $a = errAnchor(id);
        (id === 'accept_terms' ? $a : $f).addClass('is-bad');
        $f.attr('aria-invalid', 'true').attr('aria-describedby', 'ferr_' + id);
        let $e = $('#ferr_' + id);
        if (!$e.length) { $e = $('<div class="ferr" role="alert"></div>').attr('id', 'ferr_' + id); $a.after($e); }
        $e.text(msg);
    }
    function clearErr(id) {
        $('#' + id).removeClass('is-bad').removeAttr('aria-invalid').removeAttr('aria-describedby');
        if (id === 'accept_terms') $('#accept_terms').closest('label').removeClass('is-bad');
        $('#ferr_' + id).remove();
    }
    // errs = [[fieldId, message], …]. Marks every field, summarises, focuses the first.
    function showErrs(boxId, errs) {
        const $box = $('#' + boxId);
        if (!errs.length) { $box.addClass('d-none-i').removeClass('bad'); return true; }
        errs.forEach(e => setErr(e[0], e[1]));
        $box.removeClass('d-none-i').addClass('bad').text(errs.length === 1 ? errs[0][1] : fill(T.err_fix, errs.length));
        const $first = $('#' + errs[0][0]);
        const top = ($first.closest('.mb-3, .col-md-6, .col-6, label').offset() || $first.offset() || { top: 0 }).top;
        window.scrollTo({ top: Math.max(0, top - 90), behavior: 'smooth' });
        setTimeout(() => $first.trigger('focus'), 250);
        return false;
    }
    $('#stepCompany, #stepAdmin').on('input change', 'input, select', function () {
        if (!this.id || !$('#ferr_' + this.id).length) return;
        clearErr(this.id);
        const $step = $(this).closest('section');
        if (!$step.find('.ferr').length) $step.find('.err').addClass('d-none-i').removeClass('bad');
    });

    function companyErrors() {
        const errs = [];
        if ($('#company_name').val().trim().length < 2) errs.push(['company_name', T.f_name]);
        if (!S.subOk) {
            const v = $('#subdomain').val(), h = $('#subHint').text();
            if (!/^[a-z0-9][a-z0-9-]{2,31}$/.test(v)) errs.push(['subdomain', T.sub_hint]);
            else if (h === T.sub_checking) errs.push(['subdomain', T.f_sub_wait]);
            else errs.push(['subdomain', h && h !== T.sub_hint ? h : T.f_sub_taken]);
        }
        return errs;
    }
    $('#companyNext').on('click', function () {
        ['company_name', 'subdomain'].forEach(clearErr);
        if (!showErrs('errCompany', companyErrors())) return;
        show('admin');
    });

    // ── Admin fields ─────────────────────────────────────────────────────────
    // Same rules as registerTenant() on the server (core/tenant_registration.php).
    const USER_RE = /^[A-Za-z][A-Za-z0-9._-]{2,49}$/;
    const userOk = () => USER_RE.test($('#owner_username').val().trim());
    function userProblem() {
        const v = $('#owner_username').val().trim();
        if (v === '') return T.f_user_empty;
        if (USER_RE.test(v)) return '';
        if (/\s/.test(v)) return T.f_user_space;
        if (v.includes('@')) return T.f_user_at;
        if (!/^[A-Za-z]/.test(v)) return T.f_user_start;
        if (/[^A-Za-z0-9._-]/.test(v)) return T.f_user_chars;
        return T.f_user_short;
    }
    // Spaces, dashes, dots and brackets are formatting, not part of the number (the server strips them too).
    const phoneClean = v => String(v).replace(/[\s().\-]/g, '');
    function phoneProblem() {
        const raw = $('#owner_phone').val().trim(), p = phoneClean(raw);
        if (raw === '') return T.f_phone_empty;
        if (!/^\+?[0-9]+$/.test(p)) return T.f_phone_chars;
        const n = p.replace('+', '').length;
        return (n < 7 || n > 15) ? fill(T.f_phone_len, n) : '';
    }
    function checks() {
        const pw = $('#owner_password').val(), pw2 = $('#owner_password_confirm').val();
        return { len: pw.length >= 8, letter: /[A-Za-z]/.test(pw), num: /\d/.test(pw), match: pw !== '' && pw === pw2 };
    }
    function renderChecks() {
        const c = checks();
        $('#pwChecks span').each(function () {
            const ok = c[this.getAttribute('data-c')];
            $(this).toggleClass('ok', ok);
            $(this).find('.ck').text(ok ? '✓' : '○');
        });
        $('#userHint').toggleClass('ok', userOk()).text(userOk() ? T.user_ok : T.user_hint);
    }
    $('#owner_password, #owner_password_confirm, #owner_username').on('input', renderChecks);
    $('#adminBack').on('click', () => show('company'));

    $('#owner_username').on('blur', function () { const v = $(this).val().trim(); if (v !== $(this).val()) $(this).val(v); renderChecks(); });

    // Eye button: show / hide what was typed in a password box.
    $('.pw-eye').on('click', function () {
        const $i = $('#' + this.getAttribute('data-pw')), show = $i.attr('type') === 'password';
        $i.attr('type', show ? 'text' : 'password');
        $(this).attr({ 'aria-pressed': show ? 'true' : 'false', 'aria-label': show ? T.pw_hide : T.pw_show, title: show ? T.pw_hide : T.pw_show });
    });

    function adminErrors() {
        const errs = [], c = checks();
        if (!$('#owner_first_name').val().trim()) errs.push(['owner_first_name', T.f_first]);
        if (!$('#owner_last_name').val().trim()) errs.push(['owner_last_name', T.f_last]);
        const u = userProblem(); if (u) errs.push(['owner_username', u]);
        const p = phoneProblem(); if (p) errs.push(['owner_phone', p]);
        const em = $('#owner_email').val().trim();
        if (em === '') errs.push(['owner_email', T.f_email_empty]);
        else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) errs.push(['owner_email', T.f_email]);
        if ($('#owner_password').val() === '') errs.push(['owner_password', T.f_pw_empty]);
        else if (!(c.len && c.letter && c.num)) {
            const need = []; if (!c.len) need.push(T.f_pw_len); if (!c.letter) need.push(T.f_pw_letter); if (!c.num) need.push(T.f_pw_num);
            errs.push(['owner_password', fill(T.f_pw_need, need.join(', '))]);
        }
        if ($('#owner_password_confirm').val() === '') errs.push(['owner_password_confirm', T.f_pw2_empty]);
        else if (!c.match) errs.push(['owner_password_confirm', T.f_pw2]);
        if (!$('#accept_terms').is(':checked')) errs.push(['accept_terms', T.f_terms]);
        return errs;
    }
    const ADMIN_FIELDS = ['owner_first_name', 'owner_last_name', 'owner_username', 'owner_phone', 'owner_email', 'owner_password', 'owner_password_confirm', 'accept_terms'];

    // ── Submit ───────────────────────────────────────────────────────────────
    let cTimer = null;
    function startCreating() {
        const name = prod() ? prod().name : 'Smart POS';
        $('#creatingHead').text(fill(T.creating_h, name));
        const labels = [T.c1, fill(T.c2, name), T.c3, T.c4];
        let i = 0;
        const draw = () => {
            $('#cSteps').html(labels.map((l, k) => '<div class="cstep ' + (k < i ? 'done' : k === i ? 'active' : '') + '"><span class="n">' + (k < i ? '✓' : k + 1) + '</span>' + esc(l) + '</div>').join(''));
            $('#progressBar').css('width', Math.max(5, Math.min(92, i * 23)) + '%');
        };
        draw();
        clearInterval(cTimer);
        cTimer = setInterval(() => { if (i < 3) { i++; draw(); } }, 1400);
        show('creating');
        return () => { clearInterval(cTimer); i = 4; draw(); $('#progressBar').css('width', '100%'); };
    }

    $('#registerForm').on('submit', function (e) {
        e.preventDefault();
        ADMIN_FIELDS.forEach(clearErr);
        if (!showErrs('errAdmin', adminErrors())) return;
        $('#f_product').val(S.product || '');
        $('#f_plan').val(S.plan || '');
        $('#f_term').val(S.term || '');
        const finish = startCreating();
        // Random id for this signup: if the answer below is lost, the page asks
        // actions/register_status.php about it instead of reporting a failure.
        const token = Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
        const fd = new FormData(this);
        fd.append('reg_token', token);
        $.ajax({
            url: '/actions/register_tenant.php', method: 'POST', dataType: 'json',
            data: fd, contentType: false, processData: false, timeout: 120000
        }).done(function (res) {
            finish();
            created(res);
        }).fail(function (xhr) {
            let msg = null;
            try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (x) {}
            if (msg) { finish(); return failed(msg); }
            // No answer from the server — the account may still be being built.
            let tries = 0;
            (function poll() {
                $.getJSON('/actions/register_status.php', { token: token }).done(function (st) {
                    if (st.state === 'done') { finish(); return created(st.response); }
                    if (st.state === 'failed') { finish(); return failed(st.message || T.err_generic); }
                    if (st.state === 'running' && ++tries < 75) return setTimeout(poll, 4000);
                    finish(); failed(T.err_generic);
                }).fail(function () {
                    if (++tries < 75) return setTimeout(poll, 4000);
                    finish(); failed(T.err_generic);
                });
            })();
        });
    });
    // The account is ready (or the server refused it) — show the matching screen.
    function created(res) {
        if (!res || !res.success) return failed((res && res.message) || T.err_generic);
        if (navigator.sendBeacon) {
            navigator.sendBeacon('/actions/register_after.php');
            navigator.sendBeacon('/api/platform/vendor_link_kick.php');
        }
        const addr = res.subdomain + '.' + BASE;
        if (res.pending_approval) {
            $('#pendAddr').text(addr); $('#pendUser').text(res.username);
            setTimeout(() => show('pending'), 500);
            return;
        }
        const name = prod() ? prod().name : 'Smart POS';
        $('#doneHead').text(fill(T.ready_h, name));
        $('#doneSub').text($('#company_name').val().trim() + ' · ' + fill(T.trial_until, TRIAL_END));
        $('#doneAddr').text(addr);
        $('#doneUser').text(res.username);
        if (plan() && price()) {
            $('#donePlan').text(plan().name + ' · ' + price().label + ' · ' + fill(T.plan_after, fmt(price().price) + ' TZS'));
            $('#donePlanRow').removeClass('d-none-i');
        }
        $('#doneSent').text(fill(T.sent_to, $('#owner_email').val().trim()));
        $('#doneSignIn').attr('href', res.login_url + '?u=' + encodeURIComponent(res.username));
        setTimeout(() => show('done'), 500);
    }
    // Server refusal → the field it is about, in the page language. Order matters
    // ("already registered" before the plain phone/email rules).
    const SERVER_FIELDS = [
        [/phone number is already registered/i, 'admin', 'owner_phone', () => T.f_phone_taken],
        [/phone/i, 'admin', 'owner_phone', () => phoneProblem() || T.f_phone_chars],
        [/email address is already registered/i, 'admin', 'owner_email', () => T.f_email_taken],
        [/enter your email/i, 'admin', 'owner_email', () => T.f_email_empty],
        [/email/i, 'admin', 'owner_email', () => T.f_email],
        [/username/i, 'admin', 'owner_username', () => userProblem() || T.user_hint],
        [/do not match/i, 'admin', 'owner_password_confirm', () => T.f_pw2],
        [/password/i, 'admin', 'owner_password', () => T.f_pw_need.replace('%s', [T.f_pw_len, T.f_pw_letter, T.f_pw_num].join(', '))],
        [/terms/i, 'admin', 'accept_terms', () => T.f_terms],
        [/subdomain|web address/i, 'company', 'subdomain', () => T.f_sub_taken],
        [/company name/i, 'company', 'company_name', () => T.f_name],
        [/logo/i, 'company', 'company_logo', null],
    ];
    function failed(msg) {
        const hit = SERVER_FIELDS.find(r => r[0].test(msg));
        if (!hit) { show('admin'); $('#errAdmin').removeClass('d-none-i').addClass('bad').text(msg); return; }
        show(hit[1]);
        const box = hit[1] === 'company' ? 'errCompany' : 'errAdmin';
        if (hit[2] === 'company_logo') { $('#' + box).removeClass('d-none-i').addClass('bad').text(msg); return; }
        clearErr(hit[2]);
        showErrs(box, [[hit[2], hit[3]()]]);
        if (hit[2] === 'subdomain') { S.subOk = false; checkSub(); }
    }

    // ── Start ────────────────────────────────────────────────────────────────
    if (CLOSED) { renderAside(); return; }
    renderProducts();
    if (S.product && prod()) ensureSelection(); else S.product = '';
    show(hasProductStep && !S.product ? 'product' : (hasPlanStep ? 'plan' : 'company'));
})();
</script>
</body>
</html>
