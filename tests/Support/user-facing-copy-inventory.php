<?php

/** Review aid only: original byte offsets/lines survive masking; candidates are not completeness proof. */
function userFacingCopyMask(string $source, string $pattern): string
{
    return preg_replace_callback($pattern, static fn (array $m): string => preg_replace('/[^\r\n]/', ' ', $m[0]) ?? $m[0], $source) ?? $source;
}

function userFacingCopyIsCssClassList(string $text): bool
{
    $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($tokens === []) {
        return false;
    }

    $classPattern = '/^(?:tw-[\w\/.%\[\]-]+|ui-[\w-]+|text-[\w.-]+|fw-[\w-]+|d-(?:flex|inline-flex|inline-block|block|none|grid)|justify-content-[\w-]+|align-items-[\w-]+|gap-[\w.-]+|font-[\w-]+|table-[\w-]+|btn(?:-[\w-]+)?|m[trblxy]?-[\w.-]+|p[trblxy]?-[\w.-]+|[wh]-[\w.-]+|(?:is|has)-[\w-]+|[\w-]+--[\w-]+|(?:small|font-monospace|text-nowrap|text-truncate|ui-tabular-nums|rounded(?:-[\w-]+)?))$/i';

    foreach ($tokens as $token) {
        if (! preg_match($classPattern, $token)) {
            return false;
        }
    }

    return true;
}

/** Mask complete Blade directive expressions while preserving source offsets and visible prop defaults. */
function userFacingCopyMaskBladeDirectives(string $source): array
{
    $directives = [
        'auth', 'can', 'checked', 'class', 'csrf', 'disabled', 'else', 'elseif', 'empty', 'endauth', 'endcan', 'enderror',
        'endfor', 'endforeach', 'endforelse', 'endif', 'endisset', 'endonce', 'endphp', 'endpush', 'endsection',
        'endunless', 'error', 'extends', 'for', 'foreach', 'forelse', 'hassection', 'if', 'include', 'included',
        'isset', 'js', 'json', 'method', 'once', 'php', 'props', 'push', 'readonly', 'required', 'section',
        'selected', 'stack', 'starting', 'unless', 'vite', 'yield',
    ];
    $humanPropPattern = '/([\'"])(?:title|label|placeholder|aria-label|description|eyebrow|helper|meta|subtitle|caption|alt|emptyMessage|actionText|startLabel|endLabel|loadingText|copyLabel)\1\s*=>\s*([\'"])((?:\\\\.|(?!\2).)*)\2/is';
    $masked = $source;
    $propDefaults = [];
    $length = strlen($source);
    $cursor = 0;

    while (($at = strpos($source, '@', $cursor)) !== false) {
        if (($source[$at + 1] ?? '') === '@'
            || ! preg_match('/\G@([A-Za-z_][A-Za-z0-9_]*)/', $source, $match, 0, $at)
            || ! in_array(strtolower($match[1]), $directives, true)) {
            $cursor = $at + 1;

            continue;
        }

        $name = strtolower($match[1]);
        $afterName = $at + strlen($match[0]);
        $argumentStart = $afterName;
        while ($argumentStart < $length && ctype_space($source[$argumentStart])) {
            $argumentStart++;
        }

        $end = $afterName;
        $body = null;
        $bodyOffset = null;
        if (($source[$argumentStart] ?? '') === '(') {
            $depth = 0;
            $quote = null;
            $escaped = false;
            $lineComment = false;
            $blockComment = false;

            for ($index = $argumentStart; $index < $length; $index++) {
                $char = $source[$index];
                $next = $source[$index + 1] ?? '';

                if ($lineComment) {
                    if ($char === "\n") {
                        $lineComment = false;
                    }

                    continue;
                }
                if ($blockComment) {
                    if ($char === '*' && $next === '/') {
                        $blockComment = false;
                        $index++;
                    }

                    continue;
                }
                if ($quote !== null) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($char === '\\') {
                        $escaped = true;
                    } elseif ($char === $quote) {
                        $quote = null;
                    }

                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $blockComment = true;
                    $index++;

                    continue;
                }
                if (($char === '/' && $next === '/') || $char === '#') {
                    $lineComment = true;
                    if ($char === '/') {
                        $index++;
                    }

                    continue;
                }
                if (in_array($char, ["'", '"', '`'], true)) {
                    $quote = $char;

                    continue;
                }
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')' && --$depth === 0) {
                    $end = $index + 1;
                    $bodyOffset = $argumentStart + 1;
                    $body = substr($source, $bodyOffset, $index - $bodyOffset);
                    break;
                }
            }

            if ($body === null) {
                $end = $length;
            }
        }

        if ($name === 'props' && $body !== null && $bodyOffset !== null) {
            preg_match_all($humanPropPattern, $body, $defaults, PREG_OFFSET_CAPTURE);
            foreach ($defaults[3] as [$text, $offset]) {
                $propDefaults[] = ['text' => $text, 'offset' => $bodyOffset + $offset];
            }
        }

        if ($name === 'section' && $body !== null && $bodyOffset !== null
            && preg_match('/^\s*[\'"](?:title|page-title)[\'"]\s*,\s*/i', $body, $titleArgument, PREG_OFFSET_CAPTURE)) {
            $titleStart = $titleArgument[0][1] + strlen($titleArgument[0][0]);
            $titleExpression = substr($body, $titleStart);
            preg_match_all('/([\'"])((?:\\\\.|(?!\1).)*)\1/s', $titleExpression, $titleLiterals, PREG_OFFSET_CAPTURE);
            foreach ($titleLiterals[2] as [$text, $offset]) {
                $beforeTitleLiteral = substr($titleExpression, 0, $offset);
                if (preg_match('/(?:__|trans_choice|trans)\s*\([^)]*$/i', $beforeTitleLiteral)) {
                    continue;
                }
                $propDefaults[] = ['text' => $text, 'offset' => $bodyOffset + $titleStart + $offset, 'context' => 'document title literal'];
            }
        }

        $masked = substr_replace($masked, userFacingCopyMask(substr($source, $at, $end - $at), '/.*/s'), $at, $end - $at);
        $cursor = max($end, $at + 1);
    }

    return ['source' => $masked, 'props' => $propDefaults];
}

function userFacingCopyTranslationReferences(string $path, string $source): array
{
    $clean = preg_replace_callback(
        '~([\'"\x60])(?:\\\\.|(?!\1).)*?\1|/\*.*?\*/|//[^\r\n]*|\{\{--.*?--\}\}|<!--.*?-->~s',
        static fn (array $m): string => in_array($m[0][0] ?? '', ["'", '"', chr(96)], true)
            ? $m[0] : (preg_replace('/[^\r\n]/', ' ', $m[0]) ?? $m[0]), $source
    ) ?? $source;
    preg_match_all('/(?:(__|trans_choice|trans)\s*|(?:window\.)?AdasiI18n\.(t|choice)\s*)\(\s*([\'"])([a-z][a-z0-9_]*\.[a-z0-9_.]+)\3/i', $clean, $matches, PREG_OFFSET_CAPTURE);
    $references = [];
    foreach ($matches[4] as $index => [$key, $offset]) {
        $tail = substr($clean, $matches[0][$index][1] + strlen($matches[0][$index][0]), 30);
        if (str_ends_with($key, '.') || preg_match('/^\s*[.+]/', $tail)) {
            // This is a dynamic prefix, not a complete static lookup key.
            continue;
        }
        $references[] = ['path' => $path, 'key' => $key, 'call' => $matches[1][$index][0] ?: $matches[2][$index][0],
            'offset' => $offset, 'line' => 1 + substr_count(substr($source, 0, $offset), "\n")];
    }

    return $references;
}

function userFacingCopyInventorySource(string $path, string $source): array
{
    $records = [];
    $seen = [];
    $add = static function (string $kind, string $text, int $offset, string $context, ?string $reason = null) use (&$records, &$seen, $path, $source): void {
        $offset += strlen($text) - strlen(ltrim($text));
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '' || ! preg_match('/[A-Za-z]/', $text)) {
            return;
        }
        $identity = $kind.':'.$offset.':'.$text;
        if (isset($seen[$identity])) {
            return;
        }
        $seen[$identity] = true;
        $sourceBefore = substr($source, max(0, $offset - 180), min(180, $offset));
        $sourceAfter = substr($source, $offset + strlen($text), 180);
        $configurationField = null;
        $configurationListField = null;
        if (str_starts_with($path, 'config/')) {
            if (preg_match('/[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*=>\s*[\'"]\s*$/', $sourceBefore, $fieldMatch)) {
                $configurationField = strtolower($fieldMatch[1]);
            }
            foreach (['roles', 'supplier_scopes', 'company_titles', 'categories', 'channels', 'locales', 'accents', 'themes', 'densities', 'sidebar_states', 'allowed_mimes', 'allowed_extensions', 'priority_roles'] as $listField) {
                if (preg_match('/[\'"]'.preg_quote($listField, '/').'[\'"]\s*=>\s*\[[^\]]*[\'"]\s*$/i', $sourceBefore)) {
                    $configurationListField = $listField;
                    break;
                }
            }
        }

        if ($reason === null && preg_match('/(?:__|trans_choice|trans)\s*\(\s*[\'\"]([a-z][a-z0-9_]*\.[a-z0-9_.]+)[\'\"]/', $text)) {
            $reason = 'Embedded application translation lookup; dictionary parity is verified separately.';
        }

        $runtimeConfigFiles = [
            'config/auth.php', 'config/auth_security.php', 'config/broadcasting.php', 'config/cache.php',
            'config/database.php', 'config/filesystems.php', 'config/hashids.php', 'config/logging.php',
            'config/mail.php', 'config/queue.php', 'config/reverb.php', 'config/services.php',
            'config/session.php', 'config/support.php',
        ];
        if ($reason === null && in_array($path, $runtimeConfigFiles, true)) {
            $reason = 'Framework or environment configuration value, not portal display copy.';
        } elseif ($reason === null && $path === 'config/app.php') {
            $reason = 'Application branding or runtime locale/timezone configuration.';
        } elseif ($reason === null && $path === 'config/banks.php') {
            $reason = 'Canonical financial institution name from the application bank registry.';
        } elseif ($reason === null && $path === 'config/finance.php') {
            $reason = 'Environment-controlled ADASI financial account or contact data, not interface copy.';
        } elseif ($reason === null && $path === 'config/user_preferences.php' && (
            in_array($configurationField, ['theme', 'density', 'sidebar_state', 'page_size', 'accent', 'locale', 'quick_access', 'timezone', 'date_format', 'time_format', 'number_format'], true)
            || in_array($configurationListField, ['locales', 'accents', 'themes', 'densities', 'sidebar_states'], true)
            || $text === 'English' || $text === 'Bahasa Indonesia' || str_starts_with($text, 'ADASI ')
        )) {
            $reason = 'Preference enum, self-name locale option, or named accent value; labels are locale-invariant configuration choices.';
        } elseif ($reason === null && $path === 'config/notification_preferences.php' && in_array($configurationField, ['mutable_subject', 'eligibility', 'priority_roles'], true)) {
            $reason = 'Notification registry behavior key or recipient eligibility identifier.';
        } elseif ($reason === null && $path === 'config/notification_preferences.php' && $configurationListField === 'priority_roles') {
            $reason = 'Notification registry recipient role identifier.';
        } elseif ($reason === null && $path === 'config/supplier_registration.php' && in_array($configurationListField, ['company_titles', 'allowed_mimes', 'allowed_extensions'], true)) {
            $reason = 'Registration legal form or file validation value; visible labels are resolved separately.';
        } elseif ($reason === null && $path === 'config/supplier_registration.php' && $configurationListField === 'categories') {
            $reason = 'Unused legacy category registry; active registration stores the supplier-provided free-text category.';
        } elseif ($reason === null && $path === 'config/regional_display.php' && (
            in_array($configurationField, ['format', 'decimal', 'group', 'separator', 'zone_label'], true)
            || preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z0-9_*-]+)+$/i', $text)
            || in_array($text, ['d M Y', 'Y-m-d', 'd F Y', 'd/m/Y', 'H:i', 'g:i A', 'd M Y H:i', 'd M Y, H:i', 'd M H:i', 'd M'], true)
            || preg_match('/^\d{1,2} [A-Z][a-z]{2} \d{4}(?: \d{2}:\d{2}(?: UTC \x{2192} \d{1,2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2} WIB)?)?$/u', $text)
            || preg_match('/^\d{1,2}:\d{2}(?: [AP]M)?$/', $text)
            || preg_match('/^\d{1,3}(?:,\d{3})*(?:\.\d+)?$/', $text)
            || preg_match('/^Jakarta \x{2014} WIB \(UTC\+07:00\)$/u', $text)
            || in_array($text, ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], true)
        )) {
            $reason = 'Regional format identifier, timezone name, or locale-neutral formatting example.';
        } elseif ($reason === null && str_starts_with($path, 'config/') && (
            preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z0-9_*-]+)+$/i', $text)
            || in_array($configurationField, ['icon', 'route', 'active', 'slot', 'scope', 'context', 'source_event', 'priority', 'mutable_subject', 'eligibility', 'priority_roles', 'driver', 'path', 'level', 'channels', 'connection', 'database', 'table', 'queue', 'timezone', 'format', 'provider', 'host', 'port', 'method', 'prefix', 'name', 'key', 'value'], true)
            || in_array($configurationListField, ['roles', 'supplier_scopes', 'company_titles', 'channels', 'priority_roles'], true)
        )) {
            $reason = 'Configuration value is a translation lookup, route, enum, or runtime identifier, not displayed copy.';
        } elseif ($reason === null && str_starts_with($path, 'config/regional_display.php')
            && (preg_match('/^\d{1,2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2} UTC → \d{1,2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2} WIB$/u', $text)
                || preg_match('/^\d{1,2} [A-Z][a-z]{2} \d{4}$/', $text))) {
            $reason = 'Locale-neutral sample value demonstrating an existing date/time presentation format.';
        } elseif ($reason === null && $path === 'app/Exports/QuotationImportTemplateExport.php' && in_array($text, ['Available', 'Not Available'], true)) {
            $reason = 'Fixed supplier quotation spreadsheet status value; the importer relies on this English integration contract.';
        } elseif ($reason === null && $path === 'app/Support/StatusHelper.php' && (
            preg_match('/^[A-Z][A-Z0-9_]+$/', $text)
            || in_array($text, ['success', 'warning', 'info', 'error', 'neutral', 'primary', 'local_invoice', 'finance', 'registration', 'ga', 'pr', 'po', 'qc', 'claim', 'document', 'shipment', 'shipment_document', 'material_progress', 'status.', 'translator', 'goods', 'services', 'quotation', 'overdue', 'ok', 'ng', 'verified', 'received', 'pending'], true)
            || str_starts_with($text, '<span class="ui-status-chip')
        )) {
            $reason = 'Central status presenter machine value, tone, translation prefix, or static markup; display labels resolve through locale dictionaries.';
        } elseif ($reason === null && preg_match('/^(?:ADASI|PO|PR|GR|QC|GA|- GA|MTC|MFA|2FA|DPP|PPN|PPh|NPWP|NIB|NSFP|IDR|USD|JPY|CNY|OK|NG|HS Code|PDF|XLSX|CSV|kg|Kg|KG|mm|pcs|BCA|BL|WIB|Form[- ]E)$/', $text)) {
            $reason = 'Established technical acronym, unit, currency, or product identifier.';
        } elseif ($reason === null && preg_match('/^(?:[-|]\s*)?ADASI(?: Supplier)? Portal$/i', $text)) {
            $reason = 'Official ADASI portal product branding is intentionally locale-invariant.';
        } elseif ($reason === null && $context === 'display expression literal' && userFacingCopyIsCssClassList($text)) {
            $reason = 'CSS utility/state class value, not displayed copy.';
        } elseif ($reason === null && $context === 'display expression literal'
            && in_array($text, ['true', 'false', 'selected', 'checked', 'disabled', 'readonly', 'required'], true)
            && str_contains($sourceBefore, '?') && str_contains($sourceAfter, ':')) {
            $reason = 'Boolean or HTML attribute-state literal in a Blade expression, not copy.';
        } elseif ($reason === null && $context === 'display expression literal'
            && in_array($text, ['human', 'iso', 'dmy', 'datetime', 'date', 'decimal', 'international', 'indonesian', 'plain', 'datetime_comma', 'short_datetime', 'full_human'], true)
            && preg_match('/(?:RegionalDisplayFormatter|NumberFormat|->date|->timestamp|->number|number_format)/i', $sourceBefore)) {
            $reason = 'Regional date or numeric formatting profile, not displayed copy.';
        } elseif ($reason === null && $context === 'display expression literal'
            && preg_match('/<x-ui\.icon\b[^>]*$/i', $sourceBefore)
            && preg_match('/(?:name|:name)\s*=\s*[\'"]?[^>]*$/i', $sourceBefore)) {
            $reason = 'Icon identifier passed to the UI icon component, not displayed copy.';
        } elseif ($reason === null && preg_match('/^<\s*\/?\s*[a-z][a-z0-9:-]*(?:\s+[^<>]*)?\s*\/?>$/i', $text)
            && ! preg_match('/\b(?:aria-label|aria-description|title|placeholder|alt)\s*=/i', $text)) {
            $reason = 'HTML markup fragment without human-facing text or accessible-name attributes.';
        } elseif ($reason === null && preg_match('/^(?:https?:|mailto:|App\\\\|tw-|ui-|md-|bg-|btn |form-|#|\.[a-z]|data-|aria-|SELECT |UPDATE |CASE |COALESCE|COUNT\(|SUM\(|JSON_|DATE_FORMAT|ROW_NUMBER)/', $text)) {
            $reason = 'Technical selector, route, SQL, protocol, or class identifier.';
        } elseif ($reason === null && preg_match('/^(?:rgba?\(|#[0-9a-fA-F]+$|[dYHhg][\/ :,.\-YmFdHisAj]+$)/', $text)) {
            $reason = 'Color or existing Regional/date formatting contract.';
        }
        // Uppercase visible text is intentionally NOT assumed to be a DB enum.
        $records[] = ['path' => $path, 'line' => 1 + substr_count(substr($source, 0, max(0, $offset)), "\n"),
            'offset' => max(0, $offset), 'source' => $kind, 'text' => $text, 'context' => $context,
            'classification' => $reason === null ? 'review_required' : 'classified', 'reason' => $reason];
    };
    $scanPhp = static function (string $segment, int $base, string $kind, bool $display = false) use ($add): void {
        $tokens = token_get_all('<?php '.$segment);
        $cursor = -6;
        $interpolation = null;
        $depth = 0;
        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $type = is_array($token) ? $token[0] : null;
            $start = $cursor;
            $cursor += strlen($text);
            if ($type === T_OPEN_TAG || in_array($type, [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($interpolation !== null) {
                if (in_array($type, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                } elseif ($text === '}' && $depth > 0) {
                    $depth--;
                }
                if ($text === '"' && $depth === 0) {
                    if ($interpolation['display']) {
                        $add($kind, $interpolation['text'], $base + $interpolation['offset'], 'interpolated message/display context');
                    }
                    $interpolation = null;
                } else {
                    $interpolation['text'] .= $text;
                }

                continue;
            }
            $before = substr($segment, max(0, $start - 180), min(180, max(0, $start)));
            $message = $display || $kind === 'Registry' || preg_match('/(?:message|error|success|title|description|label|placeholder|body|throw\s+new|withMessages|addWarning|addFileError|headings)/i', $before);
            if ($text === '"') {
                $interpolation = ['offset' => $start + 1, 'text' => '', 'display' => $message];
                $depth = 0;

                continue;
            }
            if ($type !== T_CONSTANT_ENCAPSED_STRING || ! $message) {
                continue;
            }
            $value = substr($text, 1, -1);
            $after = substr($segment, $cursor, 20);
            $reason = null;
            if (preg_match('/(?:__|trans_choice|trans)\(\s*$/', $before) && preg_match('/^[a-z][a-z0-9_]*\.[a-z0-9_.]+$/i', $value)) {
                $reason = 'Application translation reference; existence is checked separately.';
            } elseif (preg_match('/(?:route|routeIs|view|config|asset|url|session|old|collect)\s*\(\s*$/i', $before)) {
                $reason = 'Framework route name or lookup key, not displayed copy.';
            } elseif (preg_match('/(?:===|!==|==|!=)\s*$/', $before)) {
                $reason = 'Machine comparison operand, not the displayed branch label.';
            } elseif (preg_match('/^\s*=>/', $after)) {
                $reason = 'Array field or machine map key; inspect the value separately.';
            } elseif (preg_match('/(?:\$[A-Za-z_][A-Za-z0-9_]*(?:->\w+)?|->\w+|\])\s*\[$/', $before)
                && preg_match('/^\s*\]/', $after)) {
                $reason = 'Machine array/model field access key, not displayed copy.';
            } elseif (preg_match('/in_array\s*\([^;]*,\s*\[[^\]]*$/i', $before)
                && preg_match('/^[A-Z][A-Z0-9_]*$/', $value)) {
                $reason = 'Machine enum value used in a membership condition, not displayed copy.';
            } elseif (preg_match('~^(?:required|required_if|required_with|required_without|nullable|sometimes|filled|present|accepted|confirmed|boolean|string|numeric|integer|array|file|image|date|email|url|active_url|mimes:[A-Za-z0-9_,.-]+|mimetypes:[A-Za-z0-9_,./-]+|min:\d+|max:\d+|between:\d+,\d+|size:\d+|digits:\d+|date_format:[A-Za-z0-9_:/-]+|exists:[A-Za-z0-9_,.]+|unique:[A-Za-z0-9_,.]+)$~i', $value)) {
                $reason = 'Laravel validation rule token, not user-facing copy.';
            } elseif (preg_match('/(?:->)?(?:with|where|whereIn|select|pluck|orderBy|firstWhere|has|contains|find|route|config|view|asset)\s*\([^)]*$/i', $before)
                && preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_.]+)?$/i', $value)) {
                $reason = 'Framework field, relationship, route, or lookup identifier.';
            } elseif ($display
                && preg_match('/(?:->(?:date|timestamp|number)\s*\(|NumberFormat::maxDecimals\s*\(|number_format\s*\(|->links\s*\()/i', $before)
                && in_array($value, ['human', 'iso', 'dmy', 'datetime', 'date', 'decimal', 'international', 'indonesian', 'plain', 'pagination::bootstrap-5'], true)) {
                $reason = 'Formatting profile or renderer mode argument, not displayed copy.';
            } elseif (preg_match('/(?:Log|logger)\s*(?:::|->)/i', $before)) {
                $reason = 'Developer log context; review only if the diagnostic is surfaced.';
            }
            $add($kind, $value, $base + $start + 1, $display ? 'display expression literal' : 'message/display context', $reason);
        }
    };
    $scanJs = static function (string $script, int $base, bool $forceSink = false) use ($add): void {
        $clean = preg_replace_callback(
            '~([\'"\x60])(?:\\\\.|(?!\1).)*?\1|/\*.*?\*/|//[^\r\n]*~s',
            static fn (array $m): string => in_array($m[0][0] ?? '', ["'", '"', chr(96)], true)
                ? $m[0] : (preg_replace('/[^\r\n]/', ' ', $m[0]) ?? $m[0]), $script
        ) ?? $script;
        preg_match_all('/([\'"\x60])((?:\\\\.|(?!\1).)*?)\1/s', $clean, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[2] as [$text, $offset]) {
            $before = substr($clean, max(0, $offset - 180), min(180, $offset));
            $after = substr($clean, $offset + strlen($text), 40);
            $lastQuestion = strrpos($before, '?');
            $lastColon = strrpos($before, ':');
            $isTernaryValue = preg_match('/^[\'"]?\s*:/', $after)
                && $lastQuestion !== false
                && ($lastColon === false || $lastQuestion > $lastColon);
            if (! $forceSink && ! preg_match('/(?:title|text|message|label|placeholder|aria|announce|Error|Toast|Alert|confirm|prompt|status|\.text\(|textContent|innerHTML|className|classList|(?:add|remove|toggle)Class|class\s*=|(?:data|name|field|column)\s*:|addEventListener|\.on\s*\(|(?:get|set|remove)Attribute\s*\(|createElement|textElement|querySelector|closest|matches|headers\s*:)/i', $before)) {
                continue;
            }
            $reason = null;
            if (preg_match('/console\.(?:log|info|warn|error|debug)\s*\([^)]*$/i', $before)) {
                $reason = 'Internal console diagnostic, not rendered user-facing copy.';
            } elseif (preg_match('/AdasiI18n\.(?:t|choice)\(\s*[\'"]$/', $before) && preg_match('/^[a-z][a-z0-9_]*\.[a-z0-9_.]+$/i', $text)) {
                $reason = 'Application JS translation reference; existence is checked separately.';
            } elseif (preg_match('/(?:\bclassName\s*[:=]\s*|\bclassList\.(?:add|remove|toggle)\s*\([^)]*|\.(?:add|remove|toggle)Class\s*\([^)]*|\bclass\s*=\s*)[\'"]?\s*$/i', $before)
                && userFacingCopyIsCssClassList($text)) {
                $reason = 'CSS class tokens assigned to an element, not displayed copy.';
            } elseif (in_array($text, ['true', 'false', 'null', 'undefined'], true)) {
                $reason = 'JavaScript boolean/null literal, not displayed copy.';
            } elseif (preg_match('/^(?:click|change|input|submit|focus|blur|keydown|keyup|mousedown|scroll|mouseenter|mouseleave|DOMContentLoaded|shown\.bs\.tab)(?:\s+(?:click|change|input|submit|focus|blur|keydown|keyup|mousedown|scroll))?$/i', $text)
                && preg_match('/(?:addEventListener|\.on)\s*\([^)]*$/i', $before)) {
                $reason = 'DOM event name, not displayed copy.';
            } elseif (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+$/i', $text)) {
                $reason = 'Dotted translation, route, or component lookup identifier; it is not rendered copy by itself.';
            } elseif (! $isTernaryValue && preg_match('/^[\'"]?\s*(?::|=>)\s*/', $after)) {
                $reason = 'JavaScript object or PHP array key, not displayed copy.';
            } elseif (preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $text)
                && preg_match('/(?:data|name|column|field|status|type)\s*:\s*[\'"]?\s*$/i', $before)
                && preg_match('/^[\'"]?\s*[,}\]]/', $after)) {
                $reason = 'Machine field or data-map identifier, not displayed copy.';
            } elseif (preg_match('/^(?:queued|processing|done|received|verified|failed|completed|cancelled|active|inactive)$/i', $text)
                && preg_match('/(?:status|stage|state)\s*:\s*[\'"]?\s*$/i', $before)) {
                $reason = 'Machine status value in a JS state map, not displayed copy.';
            } elseif (preg_match('/^DT_[A-Za-z0-9_]+$/', $text)) {
                $reason = 'DataTables machine field identifier, not displayed copy.';
            } elseif (in_array($text, ['disabled', 'required', 'hidden', 'title', 'name', 'type', 'status', 'action', 'id', 'href', 'src'], true)
                && preg_match('/(?:\.(?:getAttribute|setAttribute|removeAttribute|prop|attr|data)\s*\([^)]*|\.(?:disabled|hidden|href|src|status|action|id|name|type)\s*=|\.style\.)[^\r\n]*[\'"]?$/i', $before)) {
                $reason = 'DOM attribute or property name, not displayed copy.';
            } elseif (in_array($text, ['primary', 'secondary', 'success', 'warning', 'error', 'danger', 'info', 'neutral'], true)
                && preg_match('/(?:tone|variant|icon|confirmTone)\s*:\s*[\'"]?\s*$/i', $before)) {
                $reason = 'UI tone or component variant identifier, not displayed copy.';
            } elseif (in_array($text, ['queued', 'processing', 'done', 'received', 'verified', 'failed', 'completed', 'cancelled', 'active', 'inactive'], true)
                && preg_match('/(?:\.status|\.stage|status|stage)\s*(?:===|!==|[:=])\s*[\'"]?\s*$/i', $before)) {
                $reason = 'Machine status value, not displayed copy.';
            } elseif ($text === 'none' && preg_match('/\.style\.display\s*=\s*[\'"]?\s*$/i', $before)) {
                $reason = 'CSS display mode value, not displayed copy.';
            } elseif (preg_match('/^(?:div|span|button|strong|tr|td|th|a|input|select|textarea|form|li|ul|table|img)$/i', $text)
                && preg_match('/(?:createElement|textElement|querySelector|closest|matches)\s*\([^)]*$/i', $before)) {
                $reason = 'HTML element or selector token, not displayed copy.';
            } elseif (preg_match('/^\[data-[a-z0-9_-]+\]$/i', $text)
                && preg_match('/(?:querySelector|closest|matches)\s*\([^)]*$/i', $before)) {
                $reason = 'CSS data-attribute selector, not displayed copy.';
            } elseif (preg_match('/^(?:Inter|Arial|sans-serif|monospace)$/i', $text)
                && preg_match('/family\s*:\s*[\'"]?\s*$/i', $before)) {
                $reason = 'Font-family token, not displayed copy.';
            } elseif (preg_match('/^:[a-z][a-z0-9_]*$/i', $text)) {
                $reason = 'Interpolation placeholder token; the message template is reviewed separately.';
            } elseif (preg_match('/^(?:\$\{title\}\\n\\n\$\{text\})$/', $text)) {
                $reason = 'Layout separator joining independently localized dialog title and body.';
            } elseif (preg_match('/^(?:application\/json|XMLHttpRequest|X-[A-Za-z0-9-]+|GET|POST|PUT|PATCH|DELETE)$/', $text)) {
                $reason = 'HTTP protocol or header value, not displayed copy.';
            } elseif (preg_match('/^[a-z]{2}-[A-Z]{2}$/', $text)) {
                $reason = 'BCP-47 locale identifier, not user-facing copy.';
            } elseif (preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i', $text)) {
                $reason = 'MIME type protocol value, not displayed copy.';
            } elseif (preg_match('/(?:===|!==|==|!=)\s*[\'"]$/', $before)) {
                $reason = 'Machine comparison operand, not the displayed branch label.';
            }
            $add('JS', $text, $base + $offset, 'script text sink', $reason);
        }
    };
    if (str_ends_with($path, '.blade.php')) {
        $clean = userFacingCopyMask($source, '/\{\{--.*?--\}\}|<!--.*?-->|<style\b.*?<\/style>/is');
        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $clean, $scripts, PREG_OFFSET_CAPTURE);
        foreach ($scripts[1] as [$script, $offset]) {
            $scanJs($script, $offset);
        }
        if ($scripts[1] === [] && preg_match('/_scripts?\.blade\.php$/', $path)) {
            $scanJs($clean, 0);
        } else {
            $clean = userFacingCopyMask($clean, '/<script\b.*?<\/script>/is');
            preg_match_all('/\bx-data\s*=\s*([\'\"])(.*?)\1/s', $clean, $alpineAttributes, PREG_OFFSET_CAPTURE);
            foreach ($alpineAttributes[2] as [$script, $offset]) {
                $script = userFacingCopyMask($script, '/@(?:js|json)\b\s*\(.*?\)/s');
                $scanJs($script, $offset);
            }
            preg_match_all('/\bx-text\s*=\s*([\'\"])(.*?)\1/s', $clean, $textBindings, PREG_OFFSET_CAPTURE);
            foreach ($textBindings[2] as [$expression, $offset]) {
                $expression = userFacingCopyMask($expression, '/@(?:js|json)\b\s*\(.*?\)/s');
                $scanJs($expression, $offset, true);
            }
            preg_match_all('/@php\b(.*?)@endphp/s', $clean, $blocks, PREG_OFFSET_CAPTURE);
            foreach ($blocks[1] as [$body, $offset]) {
                $scanPhp($body, $offset, 'Blade');
            }
            $clean = userFacingCopyMask($clean, '/@php\b.*?@endphp/s');
            preg_match_all('/\b(title|label|placeholder|aria-label|description|eyebrow|helper|meta|subtitle|caption|alt|start-label|end-label|data-loading-text|data-export-row-explanation|data-export-source-singular|data-export-source-plural)\s*=\s*([\'"])(.*?)\2/s', $clean, $attributes, PREG_OFFSET_CAPTURE);
            foreach ($attributes[3] as $index => [$text, $offset]) {
                $name = $attributes[1][$index][0];
                if (($clean[$attributes[0][$index][1] - 1] ?? '') === ':') {
                    $scanPhp($text, $offset, 'Blade', true);
                } elseif (str_contains($text, '{{') || str_contains($text, '{!!')) {
                    preg_match_all('/\{\{(.*?)\}\}|\{!!(.*?)!!\}/s', $text, $parts, PREG_OFFSET_CAPTURE);
                    foreach ($parts[0] as $partIndex => $part) {
                        [$body, $localOffset] = $parts[1][$partIndex][1] >= 0 ? $parts[1][$partIndex] : $parts[2][$partIndex];
                        $scanPhp($body, $offset + $localOffset, 'Blade', true);
                    }
                    if (trim(userFacingCopyMask($text, '/\{\{.*?\}\}|\{!!.*?!!\}/s')) !== '') {
                        $add('Blade', $text, $offset, $name);
                    }
                } else {
                    $add('Blade', $text, $offset, $name);
                }
            }
            // ARIA role values such as alert/status are accessibility protocol tokens, not copy.
            $clean = userFacingCopyMask($clean, '/\srole\s*=\s*(?:"[^"]*"|\'[^\']*\')/is');
            preg_match_all('/\{\{(.*?)\}\}|\{!!(.*?)!!\}/s', $clean, $expressions, PREG_OFFSET_CAPTURE);
            foreach ($expressions[0] as $index => [$whole, $offset]) {
                [$body, $bodyOffset] = $expressions[1][$index][1] >= 0 ? $expressions[1][$index] : $expressions[2][$index];
                $scanPhp($body, $bodyOffset, 'Blade', true);
            }
            $clean = userFacingCopyMask($clean, '/\{\{.*?\}\}|\{!!.*?!!\}/s');
            $directiveScan = userFacingCopyMaskBladeDirectives($clean);
            foreach ($directiveScan['props'] as $default) {
                $add('Blade', $default['text'], $default['offset'], $default['context'] ?? 'component display default');
            }
            $clean = $directiveScan['source'];
            // Text between directives may be outside an HTML element. Mask tags
            // and inspect each surviving original line rather than requiring ><.
            $visible = userFacingCopyMask($clean, '/<(?:[^>\"\']|\"[^\"]*\"|\'[^\']*\')*>/s');
            foreach (preg_split('/(\r?\n)/', $visible, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [] as [$line, $offset]) {
                $add('Blade', $line, $offset, 'visible text');
            }
        }
    } elseif (str_ends_with($path, '.js')) {
        $scanJs($source, 0);
    } else {
        // Tokenize a real PHP file as its body to avoid adding a second opening tag.
        $body = str_starts_with($source, '<?php') ? substr($source, 5) : $source;
        $scanPhp($body, str_starts_with($source, '<?php') ? 5 : 0, str_starts_with($path, 'config/') ? 'Registry' : 'PHP');
    }

    return ['candidates' => $records, 'references' => userFacingCopyTranslationReferences($path, $source)];
}

function userFacingCopyInventory(string $root): array
{
    $root = rtrim($root, '/\\');
    $directories = ['resources/views', 'resources/js', 'public/assets/js', 'app/Http', 'app/Services', 'app/Notifications', 'app/Support', 'app/Models', 'app/Data', 'app/Imports', 'app/Exports', 'app/Jobs', 'config'];
    $records = $references = [];
    foreach ($directories as $directory) {
        $absolute = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory);
        if (! is_dir($absolute)) {
            continue;
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->isLink() || ! in_array($file->getExtension(), ['php', 'js'], true)) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if ($source === false) {
                continue;
            }
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $scan = userFacingCopyInventorySource($path, $source);
            array_push($records, ...$scan['candidates']);
            array_push($references, ...$scan['references']);
        }
    }

    return ['method' => 'Context-sensitive candidate extraction; semantic/manual review required. Original byte offsets and source lines retained.',
        'source_roots' => $directories, 'candidates' => $records, 'references' => $references,
        'candidate_occurrences' => count($records),
        'review_required' => count(array_filter($records, fn (array $r): bool => $r['classification'] === 'review_required')),
        'classified' => count(array_filter($records, fn (array $r): bool => $r['classification'] === 'classified'))];
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    echo json_encode(userFacingCopyInventory(dirname(__DIR__, 2)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
}
