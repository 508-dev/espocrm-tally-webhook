<?php

namespace Espo\Custom\Tools\TallyWebhook\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Custom\Entities\TallyWebhookLog;
use Espo\Entities\Attachment;
use Espo\Modules\Crm\Entities\Contact;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Receives Tally form-submission webhooks and creates a prospective-member
 * Contact (type=Prospect), mirroring how the Discord bot integration already
 * creates Contacts directly (rather than going through the Lead pipeline).
 *
 * FIELD_MAP is keyed by Tally's stable field "key" (e.g. "question_qOGXgG"),
 * not by label -- keys survive the form's question text being reworded later.
 * Sourced from the "508.dev Member Intake Form" (Tally formId 2ER4Dp) as of
 * 2026-09-15. If the form changes (fields added/removed), update this map --
 * the raw payload is always preserved on the TallyWebhookLog record either way.
 */
class PostWebhook implements Action
{
    private const KIND_PLAIN = 'plain';
    private const KIND_FULL_NAME = 'fullName';
    private const KIND_GITHUB_URL = 'githubUrl';
    private const KIND_SKILLS = 'skills';
    private const KIND_ROLES = 'roles';
    private const KIND_SENIORITY = 'seniority';
    private const KIND_CHOICE_TEXT = 'choiceText';
    private const KIND_HOURS_INT = 'hoursInt';
    private const KIND_URL_ARRAY = 'urlArray';

    /** @var array<string, array{field: string, kind: string}> */
    private const FIELD_MAP = [
        'question_qOGXgG' => ['field' => 'emailAddress', 'kind' => self::KIND_PLAIN],
        'question_QdRpj7' => ['field' => 'name', 'kind' => self::KIND_FULL_NAME],
        'question_9OZPxQ' => ['field' => 'cForeignLanguageName', 'kind' => self::KIND_PLAIN],
        'question_eLr8ye' => ['field' => 'cDiscordUsername', 'kind' => self::KIND_PLAIN],
        'question_WPRXQN' => ['field' => 'cLinkedIn', 'kind' => self::KIND_PLAIN],
        'question_a04XkB' => ['field' => 'cGitHubUsername', 'kind' => self::KIND_GITHUB_URL],
        'question_6RKG4k' => ['field' => 'cWebsiteLink', 'kind' => self::KIND_URL_ARRAY],
        // question_7oKOjZ (Resume/CV file upload) intentionally unmapped -- see note in process().
        'question_bLeXgL' => ['field' => 'addressCountry', 'kind' => self::KIND_PLAIN],
        'question_AxpNaD' => ['field' => 'cRoles', 'kind' => self::KIND_ROLES],
        'question_BZpq6Q' => ['field' => 'cSeniority', 'kind' => self::KIND_SENIORITY],
        'question_k5GXgR' => ['field' => 'cDesiredHours', 'kind' => self::KIND_HOURS_INT],
        'question_v4DVg0' => ['field' => 'cRateRange', 'kind' => self::KIND_PLAIN],
        'question_2xE89M' => ['field' => 'skills', 'kind' => self::KIND_SKILLS],
        'question_LXKBVv' => ['field' => 'cReferredBy', 'kind' => self::KIND_PLAIN],
        'question_pGoX5J' => ['field' => 'description', 'kind' => self::KIND_PLAIN],
        'question_1M4zOp' => ['field' => 'cAvailableTimes', 'kind' => self::KIND_PLAIN],
    ];

    /** Tally option text (as generated for the skills picklist) -> cSeniority enum value. */
    private const SENIORITY_MAP = [
        'junior' => 'junior',
        'mid-level' => 'midlevel',
        'senior' => 'senior',
        'staff and beyond' => 'staff',
    ];

    /**
     * "Primary role" option id -> cRoles value(s). Keyed by Tally's stable
     * option UUID (not the display text), so relabeling/fixing typos on the
     * option in Tally doesn't break the mapping. A combined Tally option
     * ("Program / Project / Product Manager") maps to more than one existing
     * cRoles value. Anything not listed here (a new option added later, or
     * one with no good existing match -- e.g. "AI Engineer", "Sales") falls
     * back to its own lowercased option text via resolveOptionTexts(), which
     * is harmless since cRoles allows custom options.
     */
    private const ROLE_ID_MAP = [
        '89937b95-a5f9-419e-a6a3-53b87a9f5dc9' => ['developer'],       // Developer
        '67d2d51f-1d2f-4fd4-8659-e1df0cfd871e' => ['designer'],       // Designer
        'c5622015-eb28-407c-bbbc-46fe081e365f' => ['data scientist'], // Data Scientist
        '6637aaf9-244f-401a-ae55-89d68e163def' => ['program manager', 'product manager'],
        'ee07e1a2-c69d-41aa-b736-90a9834526a1' => ['marketing', 'user research'],
    ];

    public const RESUME_FIELD_KEY = 'question_7oKOjZ';

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private Log $log,
    ) {}

    public function process(Request $request): Response
    {
        $this->checkSecret($request);

        $body = $request->getParsedBody();

        $formName = $this->extractFormName($body);
        $fields = $body->data->fields ?? null;

        if (!is_array($fields)) {
            $this->writeLog('error', $formName, $body, null, 'No fields array in payload.');

            throw new BadRequest('No fields array in payload.');
        }

        $contactData = $this->mapFields($fields);

        if (empty($contactData['emailAddress'])) {
            $this->writeLog('error', $formName, $body, null, 'No email address resolved from payload.');

            throw new BadRequest('No email address resolved from payload.');
        }

        $existing = $this->entityManager
            ->getRDBRepositoryByClass(Contact::class)
            ->where(['emailAddress' => $contactData['emailAddress']])
            ->findOne();

        if ($existing) {
            $this->writeLog(
                'duplicate',
                $formName,
                $body,
                $existing->getId(),
                'A Contact with this email address already exists.'
            );

            return ResponseComposer::json([
                'status' => 'duplicate',
                'contactId' => $existing->getId(),
            ]);
        }

        $contactData['type'] = 'Prospect';
        $contactData['cOnboardingState'] = 'pending';

        $contact = $this->entityManager->getRDBRepositoryByClass(Contact::class)->getNew();
        $contact->setMultiple($contactData);

        $this->entityManager->saveEntity($contact);

        $resumeWarning = $this->attachResume($body, $contact);

        $this->writeLog('created', $formName, $body, $contact->getId(), $resumeWarning);

        return ResponseComposer::json([
            'status' => 'created',
            'contactId' => $contact->getId(),
        ]);
    }

    private function checkSecret(Request $request): void
    {
        $secret = $request->getRouteParam('secret');
        $expected = $this->config->get('tallyWebhookSecret');

        if (!$expected || !$secret || !hash_equals((string) $expected, (string) $secret)) {
            $this->log->warning('TallyWebhook: rejected request with bad secret.');

            throw new Forbidden('Bad secret.');
        }
    }

    private function extractFormName(stdClass $body): string
    {
        return $body->data->formName
            ?? $body->data->formId
            ?? $body->eventType
            ?? 'Tally';
    }

    /**
     * @param stdClass[] $fields
     * @return array<string, mixed>
     */
    private function mapFields(array $fields): array
    {
        $data = [];

        foreach ($fields as $field) {
            if (!is_object($field) || !isset($field->key)) {
                continue;
            }

            $map = self::FIELD_MAP[$field->key] ?? null;

            if (!$map) {
                continue;
            }

            $value = $this->resolveFieldValue($field, $map['kind']);

            if ($map['kind'] === self::KIND_FULL_NAME) {
                $data = array_merge($data, $value);

                continue;
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $data[$map['field']] = $value;
        }

        return $data;
    }

    private function resolveFieldValue(stdClass $field, string $kind): mixed
    {
        $value = $field->value ?? null;

        return match ($kind) {
            self::KIND_PLAIN => is_array($value) ? implode(', ', array_map('strval', $value)) : $value,
            self::KIND_FULL_NAME => $this->splitFullName(is_string($value) ? $value : ''),
            self::KIND_GITHUB_URL => $this->extractGithubUsername($value),
            self::KIND_SKILLS => $this->resolveOptionTexts($field, true),
            self::KIND_ROLES => $this->resolveRoles($field),
            self::KIND_SENIORITY => $this->resolveSeniority($field),
            self::KIND_CHOICE_TEXT => $this->resolveOptionTexts($field, false)[0] ?? null,
            self::KIND_HOURS_INT => $this->resolveHoursInt($field),
            self::KIND_URL_ARRAY => (is_string($value) && $value !== '') ? [$value] : null,
            default => null,
        };
    }

    /**
     * @return array{firstName?: string, lastName?: string}
     */
    private function splitFullName(string $fullName): array
    {
        $fullName = trim($fullName);

        if ($fullName === '') {
            return [];
        }

        $parts = preg_split('/\s+/', $fullName);
        $lastName = array_pop($parts);
        $firstName = implode(' ', $parts);

        $result = ['lastName' => $lastName];

        if ($firstName !== '') {
            $result['firstName'] = $firstName;
        }

        return $result;
    }

    private function extractGithubUsername(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        if (preg_match('~github\.com/([^/?#]+)~i', $value, $m)) {
            return $m[1];
        }

        return $value;
    }

    /**
     * Resolve MULTI_SELECT/MULTIPLE_CHOICE option ids in $field->value against
     * $field->options, returning the option TEXT values. Skills/roles are
     * lowercased to match this CRM's existing lowercase convention for those
     * fields; unresolvable ids fall back to the raw id rather than being
     * silently dropped.
     *
     * @return string[]
     */
    private function resolveOptionTexts(stdClass $field, bool $lowercase): array
    {
        $value = $field->value ?? null;

        if (!is_array($value)) {
            return [];
        }

        $optionTextById = [];

        foreach (($field->options ?? []) as $option) {
            if (is_object($option) && isset($option->id)) {
                $optionTextById[$option->id] = $option->text ?? $option->id;
            }
        }

        $result = [];

        foreach ($value as $id) {
            $text = $optionTextById[$id] ?? (is_string($id) ? $id : null);

            if ($text === null) {
                continue;
            }

            $result[] = $lowercase ? strtolower($text) : $text;
        }

        return $result;
    }

    /**
     * @return string[]
     */
    private function resolveRoles(stdClass $field): array
    {
        $value = $field->value ?? null;

        if (!is_array($value)) {
            return [];
        }

        $optionTextById = [];

        foreach (($field->options ?? []) as $option) {
            if (is_object($option) && isset($option->id)) {
                $optionTextById[$option->id] = $option->text ?? $option->id;
            }
        }

        $result = [];

        foreach ($value as $id) {
            if (isset(self::ROLE_ID_MAP[$id])) {
                array_push($result, ...self::ROLE_ID_MAP[$id]);

                continue;
            }

            $text = $optionTextById[$id] ?? (is_string($id) ? $id : null);

            if ($text !== null) {
                $result[] = strtolower($text);
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * cDesiredHours is a plain int field (0-60); Tally offers it as a range
     * choice ("5-10", "40+", ...). Use the lower bound as "at least N hours".
     */
    private function resolveHoursInt(stdClass $field): ?int
    {
        $text = $this->resolveOptionTexts($field, false)[0] ?? null;

        if ($text === null) {
            return null;
        }

        if (preg_match('/\d+/', $text, $m)) {
            return (int) $m[0];
        }

        return null;
    }

    private function resolveSeniority(stdClass $field): ?string
    {
        $texts = $this->resolveOptionTexts($field, true);
        $text = $texts[0] ?? null;

        if ($text === null) {
            return null;
        }

        return self::SENIORITY_MAP[$text] ?? null;
    }

    /**
     * Download the Resume/CV file (if present in the payload) and attach it to
     * the Contact's existing "resume" field (attachmentMultiple -> Attachment,
     * hasChildren/parent). Never fails the request -- a download/save problem
     * is reported as a warning string, logged on the TallyWebhookLog record,
     * while the Contact itself stays created.
     */
    private function attachResume(stdClass $body, Contact $contact): ?string
    {
        $field = $this->findFieldByKey($body, self::RESUME_FIELD_KEY);
        $files = $field->value ?? null;

        if (!is_array($files) || count($files) === 0) {
            return null;
        }

        $warnings = [];

        foreach ($files as $file) {
            if (!is_object($file) || empty($file->url) || empty($file->name)) {
                continue;
            }

            try {
                $contents = $this->downloadFile($file->url);

                $attachment = $this->entityManager->getRDBRepositoryByClass(Attachment::class)->getNew();
                $attachment->setName($file->name);
                $attachment->setType($file->mimeType ?? 'application/octet-stream');
                $attachment->setContents($contents);
                $attachment->setTargetField('resume');
                $attachment->setRole(Attachment::ROLE_ATTACHMENT);
                $attachment->setParent($contact);

                $this->entityManager->saveEntity($attachment);
            } catch (Throwable $e) {
                $message = "Resume file '{$file->name}' failed to attach: " . $e->getMessage();
                $this->log->warning('TallyWebhook: ' . $message);
                $warnings[] = $message;
            }
        }

        return $warnings === [] ? null : implode(' | ', $warnings);
    }

    private function findFieldByKey(stdClass $body, string $key): ?stdClass
    {
        foreach (($body->data->fields ?? []) as $field) {
            if (is_object($field) && ($field->key ?? null) === $key) {
                return $field;
            }
        }

        return null;
    }

    private function downloadFile(string $url): string
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);

        $contents = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($contents === false || $httpCode >= 400) {
            throw new RuntimeException("download failed (HTTP $httpCode) $error");
        }

        return $contents;
    }

    private function writeLog(
        string $status,
        string $formName,
        stdClass $payload,
        ?string $contactId = null,
        ?string $errorMessage = null
    ): void {
        try {
            $log = $this->entityManager->getRDBRepositoryByClass(TallyWebhookLog::class)->getNew();

            $logData = [
                'status' => $status,
                'formName' => $formName,
                'payload' => $payload,
            ];

            if ($contactId) {
                $logData['contactId'] = $contactId;
            }

            if ($errorMessage) {
                $logData['errorMessage'] = $errorMessage;
            }

            $log->setMultiple($logData);

            $this->entityManager->saveEntity($log);
        } catch (Throwable $e) {
            $this->log->error('TallyWebhook: failed to write TallyWebhookLog record: ' . $e->getMessage());
        }
    }
}
