<?php
/**
 * POST — Super Admin only.
 * Suggest Technologies / Key Responsibilities / assessment for an Experience Certificate.
 *
 * Why: Prefer Gemini when configured; always fall back to role catalog so HR can
 * customize offline even when AI is unavailable.
 */
require_once __DIR__ . '/../../api/BaseAPI.php';
require_once __DIR__ . '/../../utils/super_admin.php';
require_once __DIR__ . '/../../services/GeminiService.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

/**
 * @return array{key:string,label:string,match:string[],technologies:string[],responsibilities:string[],assessment:string}
 */
function br_cert_role_packs(): array
{
    return [
        [
            'key' => 'frontend',
            'label' => 'Frontend Developer',
            'match' => ['frontend', 'front end', 'front-end', 'react', 'ui developer', 'web developer'],
            'technologies' => [
                'Frontend Development: React.js, Next.js, JavaScript (ES6+), TypeScript, HTML5, CSS3, Tailwind CSS, Material UI (MUI), Shadcn UI',
                'State Management: Redux Toolkit, Context API, TanStack Query (React Query)',
                'API Integration: REST APIs, Axios, Fetch API, JWT Authentication',
                'Forms & Validation: React Hook Form, Zod',
                'Development Tools: Git, GitHub, Postman, npm',
            ],
            'responsibilities' => [
                'Engineered dynamic and user-centric interfaces with modern React patterns and responsive layouts.',
                'Integrated frontend applications with backend services and REST APIs.',
                'Implemented forms, validation, and authenticated flows based on product requirements.',
                'Collaborated effectively with cross-functional teams to deliver production-ready features.',
                'Contributed to application updates, code reviews, and deployment readiness.',
            ],
            'assessment' => 'During {possessive} tenure, {pronoun} demonstrated strong technical expertise and professionalism in building responsive, user-centric web applications.',
        ],
        [
            'key' => 'fullstack',
            'label' => 'Full Stack Developer',
            'match' => ['full stack', 'fullstack', 'full-stack'],
            'technologies' => [
                'Frontend Development: React, Next.js, TypeScript, CSS, Tailwind CSS',
                'Backend & APIs: Node.js / PHP, REST APIs, authentication flows',
                'Database: MySQL / relational data modeling, CRUD operations',
                'Development Tools: Git, GitHub, Postman, npm',
            ],
            'responsibilities' => [
                'Developed responsive and user-friendly web interfaces using React, Next.js, TypeScript, CSS and Tailwind CSS.',
                'Integrated frontend applications with backend services and APIs.',
                'Implemented CRUD operations and database integration based on project requirements.',
                'Contributed to application updates and deployment.',
            ],
            'assessment' => 'During {possessive} tenure, {pronoun} demonstrated strong technical expertise and played a key role in designing and developing responsive web applications.',
        ],
        [
            'key' => 'backend',
            'label' => 'Backend Developer',
            'match' => ['backend', 'back end', 'back-end', 'api developer', 'php developer', 'node'],
            'technologies' => [
                'Backend Development: PHP / Node.js, REST APIs, authentication & authorization',
                'Database: MySQL, query optimization, migrations',
                'Integrations: third-party APIs, webhooks, file storage',
                'Development Tools: Git, GitHub, Postman, cURL',
            ],
            'responsibilities' => [
                'Designed and implemented reliable backend APIs and business logic.',
                'Built authentication, authorization, and data-access layers for production apps.',
                'Optimized database queries and maintained migration safety.',
                'Collaborated with frontend teams to deliver end-to-end features.',
            ],
            'assessment' => 'During {possessive} tenure, {pronoun} demonstrated strong backend engineering skills and reliable ownership of API and data layers.',
        ],
        [
            'key' => 'creative',
            'label' => 'Creative Designer',
            'match' => ['creative', 'designer', 'ui/ux', 'ui ux', 'graphic', 'design', 'creator'],
            'technologies' => [
                'Design Tools: Figma, Adobe Illustrator, Photoshop, Canva',
                'UI Systems: design systems, component libraries, brand kits',
                'Motion & Export: WebP/PNG export, social formats, print layouts',
                'Collaboration: Notion / briefs, feedback cycles, versioned assets',
            ],
            'responsibilities' => [
                'Created brand-consistent visual designs for digital products and campaigns.',
                'Produced UI mockups, posters, and marketing creatives aligned with CODO guidelines.',
                'Collaborated with product and development teams to refine user experience.',
                'Maintained organized asset libraries and export-ready deliverables.',
            ],
            'assessment' => 'During {possessive} tenure, {pronoun} demonstrated creativity, attention to detail, and strong ownership of brand-quality visual deliverables.',
        ],
        [
            'key' => 'tester',
            'label' => 'QA / Tester',
            'match' => ['tester', 'qa', 'quality', 'test engineer', 'sdet'],
            'technologies' => [
                'Testing: manual QA, exploratory testing, regression suites',
                'Tracking: BugRicer / issue workflows, retests, evidence capture',
                'API Checks: Postman, status codes, contract validation',
                'Browsers & Devices: Chrome, Safari, responsive and cross-browser checks',
            ],
            'responsibilities' => [
                'Executed functional, regression, and exploratory test cycles across releases.',
                'Logged clear defect reports with reproduction steps and evidence.',
                'Verified fixes through retests and prevented regressions on critical flows.',
                'Collaborated with developers to improve product quality and release readiness.',
            ],
            'assessment' => 'During {possessive} tenure, {pronoun} demonstrated thorough testing discipline and a strong commitment to product quality.',
        ],
        [
            'key' => 'data_science',
            'label' => 'Data Science / AI',
            'match' => ['data science', 'data scientist', 'machine learning', 'ml engineer', 'ai intern', 'nlp'],
            'technologies' => [
                'Programming: Python, Java, JavaScript',
                'Data Analysis & Visualization: Pandas, NumPy, Power BI, Tableau, MySQL',
                'Machine Learning: Scikit-learn, Random Forest, LightGBM, XGBoost',
                'AI & NLP: Hugging Face Transformers, Rasa',
                'Development Tools: Jupyter Notebook, Streamlit, Git, GitHub',
            ],
            'responsibilities' => [
                'Performed data preprocessing, cleaning, and exploratory data analysis using Python.',
                'Developed and evaluated machine learning models for classification and prediction tasks.',
                'Applied machine learning algorithms including Random Forest, LightGBM, and XGBoost.',
                'Created data visualizations and dashboards to present analytical insights.',
                'Developed interactive data science applications using Streamlit.',
            ],
            'assessment' => 'During {possessive} tenure, {pronoun} demonstrated strong analytical ability and practical exposure to data science and AI workflows.',
        ],
        [
            'key' => 'general',
            'label' => 'General Staff',
            'match' => [],
            'technologies' => [
                'Productivity: Google Workspace / Microsoft 365, documentation tools',
                'Collaboration: Slack / team chat, project trackers',
                'Delivery: task ownership, reporting, stakeholder communication',
            ],
            'responsibilities' => [
                'Supported day-to-day project delivery and cross-team coordination.',
                'Maintained clear documentation and timely status updates.',
                'Contributed to process improvements and team goals.',
                'Uphold professional conduct and reliable ownership of assigned work.',
            ],
            'assessment' => 'During {possessive} tenure, {pronoun} demonstrated professionalism, dedication, and reliable ownership of assigned responsibilities.',
        ],
    ];
}

function br_cert_fill_assessment(string $template, string $salutation): string
{
    $salutation = strtolower(trim($salutation));
    if ($salutation === 'ms') {
        $pronoun = 'she';
        $possessive = 'her';
    } elseif ($salutation === 'mx') {
        $pronoun = 'they';
        $possessive = 'their';
    } else {
        $pronoun = 'he';
        $possessive = 'his';
    }
    return str_replace(
        ['{pronoun}', '{possessive}'],
        [$pronoun, $possessive],
        $template
    );
}

function br_cert_match_pack(string $designation, string $appRole): array
{
    $hay = strtolower(trim($designation . ' ' . $appRole));
    $hay = preg_replace('/[_-]+/', ' ', $hay) ?? $hay;
    $packs = br_cert_role_packs();
    foreach ($packs as $pack) {
        if (($pack['key'] ?? '') === 'general') {
            continue;
        }
        foreach ($pack['match'] as $needle) {
            if ($needle !== '' && str_contains($hay, $needle)) {
                return $pack;
            }
        }
    }
    $role = strtolower(trim($appRole));
    foreach ($packs as $pack) {
        if ($role === 'tester' && $pack['key'] === 'tester') {
            return $pack;
        }
        if ($role === 'creator' && $pack['key'] === 'creative') {
            return $pack;
        }
        if ($role === 'developer' && $pack['key'] === 'frontend') {
            return $pack;
        }
    }
    foreach ($packs as $pack) {
        if ($pack['key'] === 'general') {
            return $pack;
        }
    }
    return $packs[0];
}

function br_cert_catalog_payload(string $designation, string $appRole, string $salutation): array
{
    $pack = br_cert_match_pack($designation, $appRole);
    return [
        'pack_key' => $pack['key'],
        'pack_label' => $pack['label'],
        'technologies' => implode("\n", $pack['technologies']),
        'responsibilities' => implode("\n", $pack['responsibilities']),
        'assessment' => br_cert_fill_assessment((string)$pack['assessment'], $salutation),
        'source' => 'catalog',
    ];
}

function br_cert_sanitize_lines($value, int $maxLines = 12, int $maxLineLen = 240): string
{
    if (!is_string($value)) {
        return '';
    }
    $lines = preg_split("/\r\n|\n|\r/", $value) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $clean = trim(strip_tags((string)$line));
        $clean = preg_replace('/^[-•*]\s*/u', '', $clean) ?? $clean;
        if ($clean === '') {
            continue;
        }
        $out[] = mb_substr($clean, 0, $maxLineLen);
        if (count($out) >= $maxLines) {
            break;
        }
    }
    return implode("\n", $out);
}

try {
    $api = new BaseAPI();
    $decoded = $api->validateToken();
    if (($decoded->role ?? '') !== 'admin') {
        $api->sendJsonResponse(403, 'Only admins can request certificate suggestions');
        exit();
    }
    if (!br_actor_is_super_admin($api->getConnection(), $decoded)) {
        $api->sendJsonResponse(403, 'Only a Super Admin can request certificate suggestions');
        exit();
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = [];
    }

    $designation = trim(strip_tags((string)($data['designation'] ?? '')));
    $appRole = trim(strip_tags((string)($data['app_role'] ?? $data['role'] ?? '')));
    $salutation = trim(strip_tags((string)($data['salutation'] ?? 'Mr')));
    $fullName = trim(strip_tags((string)($data['full_name'] ?? '')));
    $employmentType = trim(strip_tags((string)($data['employment_type'] ?? 'full-time')));

    if ($designation === '') {
        $api->sendJsonResponse(422, 'Designation is required for suggestions');
        exit();
    }

    $catalog = br_cert_catalog_payload($designation, $appRole, $salutation);
    $gemini = new GeminiService();

    if (!$gemini->isConfigured()) {
        $api->sendJsonResponse(200, 'Certificate suggestions ready', $catalog);
        exit();
    }

    $prompt = implode("\n", [
        'You write Experience Certificate content for CODO AI Innovations (software company, Malappuram, Kerala).',
        'Match the tone of formal CODO HR certificates: professional, factual, concise.',
        'Employee designation/role: ' . $designation,
        $appRole !== '' ? ('App role: ' . $appRole) : '',
        $fullName !== '' ? ('Employee name: ' . $fullName) : '',
        'Employment type: ' . $employmentType,
        'Salutation: ' . $salutation,
        'Return ONLY a JSON object with keys:',
        'technologies (string, one category per line as "Category: tools...", 3-6 lines),',
        'responsibilities (string, one achievement per line, 4-6 lines, no leading bullets),',
        'assessment (string, one short paragraph about tenure/performance; use he/she/they matching salutation).',
        'Do not invent employer names other than CODO AI Innovations. No markdown fences.',
    ]);

    try {
        $raw = $gemini->generateFromTurns(
            [['role' => 'user', 'text' => $prompt]],
            ['responseMimeType' => 'application/json', 'temperature' => 0.55, 'maxOutputTokens' => 1200]
        );
        $json = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $raw)), true);
        if (!is_array($json)) {
            throw new RuntimeException('unreadable_json');
        }

        $technologies = br_cert_sanitize_lines($json['technologies'] ?? '', 8, 260);
        $responsibilities = br_cert_sanitize_lines($json['responsibilities'] ?? '', 8, 280);
        $assessment = trim(strip_tags((string)($json['assessment'] ?? '')));
        $assessment = mb_substr($assessment, 0, 600);

        if ($technologies === '' || $responsibilities === '' || $assessment === '') {
            throw new RuntimeException('incomplete_ai');
        }

        $api->sendJsonResponse(200, 'Certificate suggestions ready', [
            'pack_key' => $catalog['pack_key'],
            'pack_label' => $catalog['pack_label'],
            'technologies' => $technologies,
            'responsibilities' => $responsibilities,
            'assessment' => $assessment,
            'source' => 'ai',
        ]);
        exit();
    } catch (Throwable $e) {
        error_log(json_encode([
            'event' => 'experience_certificate.suggest.failed',
            'user_id' => (string)($decoded->user_id ?? ''),
            'error' => $e->getMessage(),
        ]));
        // Catalog fallback — never block HR certificate drafting.
        $api->sendJsonResponse(200, 'Certificate suggestions ready (catalog fallback)', $catalog);
        exit();
    }
} catch (Throwable $e) {
    error_log('experience_certificate_suggest: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Failed to suggest certificate content']);
}
