(function () {
    const SELECTOR = 'input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="reset"]), select, textarea';

    const exactDescriptions = {
        'ad location': 'Choose the advertising placement you are asking about. The dimensions shown beside each option are the required banner size.',
        'address': 'Enter the street or physical address where this business or contact can be located.',
        'budget': 'Enter the estimated amount available for this request. Leave it blank only when the budget is not fixed.',
        'business hours': 'Set when the business is open. Use the 24/7 option only if the business operates every day and hour.',
        'business address': 'Enter the street or physical address connected to this business.',
        'business email address': 'Enter the email address customers and registry admins should use for this business.',
        'business model': 'Select how the organization primarily sells, serves, or operates.',
        'business name': 'Enter the official or public name of the business.',
        'business type': 'Select the category that best describes the business or organization.',
        'confirm password': 'Re-enter the password exactly so the account can be created securely.',
        'campaign title': 'Enter a short internal name for this ad campaign. It helps admins identify the submission during review.',
        'capabilities': 'Select the platform permissions this administrator should have.',
        'category': 'Choose the category that best matches the business, request, or record you are creating.',
        'city': 'Enter the city or local area connected to this record.',
        'country': 'Choose the country that applies to this business, owner, or configuration.',
        'cta text': 'Enter the short button text users should see on the advert, such as Learn More or Visit Us.',
        'cta url': 'Enter the full destination link users should open after clicking the advert.',
        'desktop banner': 'Upload the desktop version of the creative. The exact pixel size depends on the selected placement and is shown below this field.',
        'desired start date': 'Choose when you would prefer the advert to start. If left blank, it can start after admin approval.',
        'description': 'Provide clear details that explain what this record, business, request, or issue is about.',
        'duration package': 'Choose how long the ad should run. The selected package locks the campaign duration and price.',
        'email': 'Enter an active email address that can receive platform messages and notifications.',
        'email used for your login': 'Enter the email address this owner will use to sign in and receive registry notifications.',
        'espees api key / token': 'Enter the Espees merchant API key used for secure server-side payment requests.',
        'espees merchant code': 'Enter the merchant code SBO owners should use for manual Espees payment.',
        'establishment date': 'Select when the organization was established, if known.',
        'fee': 'Enter the registration fee amount for this band.',
        'flat fee non-share entities': 'Enter the registration fee for non-share structures such as guarantee, sole proprietor, or partnership.',
        'first name': 'Enter the person’s given name.',
        'issued shares range': 'Choose the share-capital range that best matches the company. The system uses it to calculate the fee.',
        'key': 'Enter a short lowercase system key using letters, numbers, underscores, or hyphens only.',
        'kc handle': 'Enter the person’s KingsChat handle so the registry team can identify or contact them there.',
        'kingschat handle / url': 'Enter the KingsChat handle or profile URL for this contact.',
        'kingschat handle': 'Enter the owner’s KingsChat handle so the registry team can identify or follow up with them.',
        'label': 'Enter the readable name admins and users should see for this option.',
        'legal structure': 'Choose the legal structure that applies to this business registration.',
        'last name': 'Enter the person’s family name or surname.',
        'location': 'Choose the location that should filter or apply to this record.',
        'local assembly': 'Enter the owner’s Loveworld local assembly or church group.',
        'max equity': 'Enter the highest share-capital value covered by this band. Leave blank only for the open-ended final band.',
        'max equity blank = open': 'Enter the highest share-capital value covered by this band, or leave blank for the open-ended final band.',
        'message': 'Write the details the receiving team needs in order to understand and act on your request.',
        'merchant wallet': 'Enter the Espees merchant wallet where checkout payments should settle.',
        'mobile banner': 'Upload the mobile version of the creative when the selected placement requires one. The exact pixel size is shown below this field.',
        'name': 'Enter the full name of the person or record.',
        'number of employees': 'Choose the approximate team size for the business.',
        'no. of employees': 'Enter the approximate number of people working in the business.',
        'order deadline': 'Select the final date for vendors or respondents to submit their response.',
        'password': 'Create a strong password for this account and keep it private.',
        'payment reference': 'Enter the transaction ID or reference from the payment provider or transfer receipt.',
        'payment app': 'Enter the payment app name shown to SBO owners in manual payment instructions.',
        'per-share value': 'Enter the value of one share. Type 1 if you are unsure so the system can estimate the issued share volume.',
        'placement': 'Choose where the advert will appear on the platform. This controls the required banner dimensions.',
        'primary contact email': 'Enter the email address for the main person responsible for this record.',
        'primary contact name': 'Enter the full name of the main contact person.',
        'proof of payment': 'Upload a clear payment receipt or proof file in the accepted format and size.',
        'quick preset': 'Choose a preset to quickly apply a standard group of permissions.',
        'reason': 'Choose or enter the reason for this action so the team can understand the context.',
        'region': 'Choose the region that applies to this record.',
        'request type': 'Select whether vendors should respond with a product, service, or both.',
        'required documents from bidders': 'List any files vendors must include with their response.',
        'required expertise': 'Describe the skills or specialization needed to fulfill the request.',
        'sbo': 'Choose the SBO account this action should apply to.',
        'sbo number': 'Enter the application ID or registration number connected to this request.',
        'search users': 'Type a name or email to find an existing user account.',
        'service duration': 'Enter how long the service is expected to take, such as two weeks or three months.',
        'service scope': 'Describe the work, deliverables, and expected outcome for the service request.',
        'share capital type': 'Choose the share-capital classification that matches the selected company structure.',
        'share structure': 'Choose the company structure used to calculate registration fees.',
        'short description': 'Summarize the business, request, or record in one or two clear sentences.',
        'surname': 'Enter the owner’s family name or surname.',
        'state': 'Choose the state or province that applies to this address.',
        'subject': 'Enter a short summary of what this request is about.',
        'terms & conditions': 'Add important delivery, payment, compliance, or participation terms.',
        'total number of years as member': 'Enter how many years the owner has been a Loveworld member.',
        'title': 'Enter a short, descriptive title that makes this item easy to identify.',
        'unit / specification': 'Describe the unit, size, model, or specification vendors should quote for.',
        'user': 'Choose the user account this action should apply to.',
        'website': 'Enter the full website URL, beginning with http:// or https://.',
        'website if any, e.g. www.example.com': 'Enter the business website if available. Include https:// when possible.',
        'year established': 'Select the year the business was established.',
        'your review': 'Share your experience clearly and respectfully so others can understand your feedback.'
    };

    const keywordDescriptions = [
        [/password/i, 'Enter the account password. Use a strong password and keep it private.'],
        [/confirm/i, 'Re-enter the value exactly to confirm it is correct.'],
        [/phone|telephone|mobile/i, 'Enter a reachable phone number, including the country code when needed.'],
        [/email/i, 'Enter an active email address that can receive platform messages and notifications.'],
        [/url|website|link/i, 'Enter a complete URL, including https:// where possible.'],
        [/date|deadline/i, 'Choose the relevant date for this action or record.'],
        [/amount|fee|price|budget|capital|value/i, 'Enter a numeric amount without extra words or symbols unless the field asks for them.'],
        [/file|document|upload|proof|banner|creative|image|photo|picture/i, 'Upload the required file in the accepted format and size for this field.'],
        [/message|description|reason|details|comment|scope|terms/i, 'Enter clear details that help the team understand and process this request.'],
        [/category|type|status|preset|structure|location|country|state|region|placement|package/i, 'Select the option that best matches this record.'],
        [/name|title|subject/i, 'Enter a short, clear value that makes this item easy to identify.']
    ];

    function normalize(value) {
        return String(value || '')
            .replace(/\s*\((optional|required|max[^)]*)\)/gi, '')
            .replace(/\b(optional|required)\b/gi, '')
            .replace(/[“”"':*]+/g, '')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();
    }

    function labelText(label) {
        const clone = label.cloneNode(true);
        clone.querySelectorAll('.form-help-tooltip-trigger, .text-danger, .invalid-feedback').forEach((node) => node.remove());

        return clone.textContent.replace(/\s+/g, ' ').trim();
    }

    function controlForLabel(label) {
        if (label.htmlFor) {
            const escapedId = window.CSS && CSS.escape ? CSS.escape(label.htmlFor) : label.htmlFor.replace(/"/g, '\\"');
            const control = document.getElementById(label.htmlFor) || document.querySelector(`[id="${escapedId}"]`);

            if (control) {
                return control;
            }
        }

        const wrapped = label.querySelector(SELECTOR);

        if (wrapped) {
            return wrapped;
        }

        const fieldGroup = label.closest('.mb-3, .col, [class*="col-"], .form-group, .modal-body, .card-body, form');

        return fieldGroup ? fieldGroup.querySelector(SELECTOR) : null;
    }

    function humanize(value) {
        const raw = String(value || '').trim();
        const key = /\s/.test(raw) ? raw : raw.replace(/[._-]+/g, ' ');

        return key
            .replace(/[_-]+/g, ' ')
            .replace(/([a-z])([A-Z])/g, '$1 $2')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function controlName(control) {
        return control.getAttribute('aria-label')
            || control.getAttribute('placeholder')
            || control.getAttribute('name')
            || control.getAttribute('wire:model')
            || control.getAttribute('wire:model.defer')
            || control.getAttribute('wire:model.live')
            || control.id
            || 'this field';
    }

    function fieldKey(control, label = null) {
        return normalize([
            label ? labelText(label) : '',
            control.getAttribute('name'),
            control.getAttribute('wire:model'),
            control.getAttribute('wire:model.defer'),
            control.getAttribute('wire:model.live'),
            control.id,
            control.getAttribute('placeholder'),
            control.getAttribute('aria-label')
        ].filter(Boolean).join(' '));
    }

    function descriptorFor(control, label = null) {
        const explicit = control.getAttribute('data-form-help') || (label ? label.getAttribute('data-form-help') : null);

        if (explicit) {
            return explicit;
        }

        const labelKey = label ? normalize(labelText(label)) : '';
        const nameKey = normalize(control.getAttribute('name') || control.getAttribute('wire:model') || control.id);
        const placeholderKey = normalize(control.getAttribute('placeholder'));
        const ariaKey = normalize(control.getAttribute('aria-label'));
        const combined = fieldKey(control, label);

        if (exactDescriptions[labelKey]) {
            return exactDescriptions[labelKey];
        }

        if (exactDescriptions[nameKey]) {
            return exactDescriptions[nameKey];
        }

        if (exactDescriptions[placeholderKey]) {
            return exactDescriptions[placeholderKey];
        }

        if (exactDescriptions[ariaKey]) {
            return exactDescriptions[ariaKey];
        }

        for (const [pattern, message] of keywordDescriptions) {
            if (pattern.test(combined)) {
                return message;
            }
        }

        const placeholder = control.getAttribute('placeholder');

        if (placeholder) {
            return `Use this field to provide: ${placeholder}`;
        }

        if (label && labelKey) {
            return `Provide the correct ${labelText(label).toLowerCase()} for this form.`;
        }

        return null;
    }

    function shouldSkipControl(control) {
        const type = (control.getAttribute('type') || '').toLowerCase();
        const form = control.closest('form');
        const formClass = form ? String(form.className || '') : '';

        return control.dataset.formHelpControlReady === 'true'
            || control.closest('[data-form-help-skip]')
            || control.closest('.navbar-search, .hero-search-form')
            || /search/i.test(formClass)
            || ['hidden', 'submit', 'button', 'reset', 'checkbox', 'radio'].includes(type)
            || control.disabled
            || control.readOnly;
    }

    function shouldSkip(control, label) {
        return label.dataset.formHelpReady === 'true'
            || label.closest('[data-form-help-skip]')
            || shouldSkipControl(control);
    }

    function enhanceLabel(label) {
        const control = controlForLabel(label);

        if (!control || shouldSkip(control, label)) {
            return;
        }

        // Keep the shared enhancement pass responsible for generated labels and
        // readiness flags, but do not add hover-only help controls to fields.
        // Inline labels and validation copy are easier to scan and work better
        // on touch screens than a question-mark trigger beside every label.
        label.classList.add('form-help-label');
        label.dataset.formHelpReady = 'true';
        control.dataset.formHelpControlReady = 'true';
    }

    function controlHasLabel(control) {
        if (control.closest('label')) {
            return true;
        }

        if (!control.id) {
            return false;
        }

        const escapedId = window.CSS && CSS.escape ? CSS.escape(control.id) : control.id.replace(/"/g, '\\"');

        return Boolean(document.querySelector(`label[for="${escapedId}"]`));
    }

    function ensureControlId(control) {
        if (control.id) {
            return;
        }

        const base = normalize(controlName(control)).replace(/[^a-z0-9]+/g, '-') || 'field';
        let id = `form-help-${base}`;
        let index = 1;

        while (document.getElementById(id)) {
            id = `form-help-${base}-${index}`;
            index += 1;
        }

        control.id = id;
    }

    function enhanceUnlabeledControl(control) {
        if (shouldSkipControl(control) || controlHasLabel(control)) {
            return;
        }

        const description = descriptorFor(control);

        if (!description) {
            return;
        }

        ensureControlId(control);

        const label = document.createElement('label');
        label.className = 'form-label form-help-generated-label';
        label.htmlFor = control.id;
        label.textContent = humanize(controlName(control));
        label.setAttribute('data-form-help', description);

        const insertionTarget = control.closest('.input-group, .position-relative') || control;
        insertionTarget.parentNode.insertBefore(label, insertionTarget);

        enhanceLabel(label);
    }

    function enhanceForms(root = document) {
        root.querySelectorAll('label').forEach(enhanceLabel);
        root.querySelectorAll(SELECTOR).forEach(enhanceUnlabeledControl);
        setupAdCampaignDimensionHelp(root);
    }

    function optionDimension(option, key) {
        const value = option ? option.dataset[key] : null;

        return value && Number(value) > 0 ? Number(value) : null;
    }

    function setupAdCampaignDimensionHelp(root = document) {
        const select = root.querySelector('#campaign-slot') || document.querySelector('#campaign-slot');
        const rateSelect = document.querySelector('#campaign-rate');
        const desktopHelp = document.querySelector('[data-ad-desktop-help]');
        const mobileHelp = document.querySelector('[data-ad-mobile-help]');
        const mobileInput = document.querySelector('#campaign-mobile');
        const summary = document.querySelector('[data-ad-campaign-summary]');
        const summaryPlacement = document.querySelector('[data-ad-summary-placement]');
        const summaryDesktop = document.querySelector('[data-ad-summary-desktop]');
        const summaryMobile = document.querySelector('[data-ad-summary-mobile]');
        const summaryRate = document.querySelector('[data-ad-summary-rate]');

        if (!select || select.dataset.dimensionHelpReady === 'true') {
            return;
        }

        select.dataset.dimensionHelpReady = 'true';

        const update = () => {
            const option = select.options[select.selectedIndex];
            const desktopWidth = optionDimension(option, 'desktopWidth');
            const desktopHeight = optionDimension(option, 'desktopHeight');
            const mobileWidth = optionDimension(option, 'mobileWidth');
            const mobileHeight = optionDimension(option, 'mobileHeight');
            const hasDesktop = desktopWidth && desktopHeight;
            const hasMobile = mobileWidth && mobileHeight;
            const placementLabel = select.value
                ? (option.dataset.slotLabel || option.textContent || 'Selected placement').trim()
                : 'Not selected';

            if (desktopHelp) {
                desktopHelp.classList.toggle('is-ready', Boolean(hasDesktop));
                desktopHelp.classList.toggle('is-warning', !hasDesktop);
                desktopHelp.textContent = hasDesktop
                    ? `Required desktop size: ${desktopWidth}×${desktopHeight} px. Upload JPG, PNG, or WebP up to 5 MB.`
                    : 'Select a placement to see the exact required desktop banner size.';
            }

            if (mobileHelp) {
                mobileHelp.classList.toggle('is-ready', Boolean(hasMobile));
                mobileHelp.classList.toggle('is-warning', !hasMobile);
                mobileHelp.textContent = hasMobile
                    ? `Required mobile size: ${mobileWidth}×${mobileHeight} px. This mobile creative is required for the selected placement.`
                    : 'This placement does not require a separate mobile banner. Upload one only if requested later.';
            }

            if (mobileInput) {
                mobileInput.required = Boolean(hasMobile);
                mobileInput.setAttribute('aria-required', hasMobile ? 'true' : 'false');
            }

            if (rateSelect) {
                let selectedRateIsAvailable = false;

                Array.from(rateSelect.options).forEach((rateOption) => {
                    if (!rateOption.value) {
                        return;
                    }

                    const matchesSelectedSlot = !select.value || rateOption.dataset.slot === select.value;
                    rateOption.hidden = !matchesSelectedSlot;
                    rateOption.disabled = !matchesSelectedSlot;

                    if (rateOption.selected && matchesSelectedSlot) {
                        selectedRateIsAvailable = true;
                    }
                });

                if (select.value && rateSelect.value && !selectedRateIsAvailable) {
                    rateSelect.value = '';
                }
            }

            if (summary) {
                summary.classList.toggle('is-ready', Boolean(select.value));
            }

            if (summaryPlacement) {
                summaryPlacement.textContent = placementLabel;
            }

            if (summaryDesktop) {
                summaryDesktop.textContent = hasDesktop
                    ? `${desktopWidth}×${desktopHeight} px`
                    : 'Choose placement';
            }

            if (summaryMobile) {
                summaryMobile.textContent = hasMobile
                    ? `${mobileWidth}×${mobileHeight} px required`
                    : (select.value ? 'Desktop creative only' : 'Choose placement');
            }

            if (summaryRate && rateSelect) {
                const rateOption = rateSelect.options[rateSelect.selectedIndex];
                const price = Number(rateOption?.dataset.price || 0);
                const currency = rateOption?.dataset.currency || 'Espees';
                const rateLabel = rateOption?.dataset.rateLabel || '';

                summaryRate.textContent = rateSelect.value && rateLabel
                    ? `${rateLabel} - ${new Intl.NumberFormat().format(price)} ${currency}`
                    : 'Not selected';
            }
        };

        select.addEventListener('change', update);
        if (rateSelect) {
            rateSelect.addEventListener('change', update);
        }
        update();
    }

    let scheduled = false;

    function scheduleEnhance(root = document) {
        if (scheduled) {
            return;
        }

        scheduled = true;
        window.requestAnimationFrame(() => {
            scheduled = false;
            enhanceForms(root);
        });
    }

    document.addEventListener('DOMContentLoaded', () => scheduleEnhance());
    document.addEventListener('livewire:navigated', () => scheduleEnhance());
    document.addEventListener('livewire:initialized', () => scheduleEnhance());

    const observer = new MutationObserver(() => scheduleEnhance());

    document.addEventListener('DOMContentLoaded', () => {
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    });

    window.LWRegistryFormHelp = {
        enhance: enhanceForms
    };
})();
