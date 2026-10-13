<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $sbo->name }} — Kingdom Commerce Trading License</title>
    {{--
        Kingdom Commerce Trading License.

        The artwork is the supplied design itself
        (resources/images/certificate-template.jpg), embedded as a data URI and
        used as the page. That is deliberate rather than lazy: the sheet carries
        gilt corner ornaments, a column watermark, two embossed seals, the Seven
        Pillars icon set and a signature block — none of which exist as separate
        assets, and all of which dompdf would approximate badly if redrawn in
        CSS. This replaces a plain typographic certificate that shared nothing
        with the design.

        What is drawn here is only what the design leaves blank: the ruled
        fields, and a real QR code over the artwork's placeholder one.

        Positions are percentages measured against the source image (1280x905),
        whose aspect ratio is 1.4144 — A4 landscape is 1.4143, so the artwork
        fills the page with no letterboxing and the percentages hold.

        dompdf notes: no flexbox, no grid, and `position: absolute` is only
        reliable inside a positioned ancestor with explicit dimensions, hence
        the fixed-size sheet rather than a percentage-height body.
    --}}
    <style>
        @page { size: A4 landscape; margin: 0; }

        html, body {
            margin: 0;
            padding: 0;
            width: 297mm;
            height: 210mm;
        }

        .sheet {
            position: relative;
            width: 297mm;
            height: 210mm;
        }

        .sheet > img.art {
            position: absolute;
            top: 0;
            left: 0;
            width: 297mm;
            height: 210mm;
        }

        .field {
            position: absolute;
            font-family: "Times New Roman", Times, serif;
            font-size: 9pt;
            color: #1a1a1a;
        }

        /* The identifiers, in the accent the design uses for its BUSINESS
           DETAILS heading — so the numbers someone is looking for stand out
           from the rest of the ruled line. */
        .field--key { color: #1d21b5; font-weight: bold; }

        .field--status { font-weight: bold; }

        /* A long trading name or sector shrinks rather than running past its
           rule and into the next column. */
        .field--tight { font-size: 7.5pt; }

        /* Sits over the artwork's placeholder code. */
        .qr {
            position: absolute;
            left: 77.9%;
            top: 73.1%;
            width: 10.4%;
        }

        .qr img { width: 100%; }
    </style>
</head>
<body>
    <div class="sheet">
        <img class="art" src="{{ $template }}" alt="">

        {{-- Business Name · Kingdom Business ID · Trading License Number --}}
        <span class="field field--tight" style="left: 18.9%; top: 41.3%;">{{ $fields['name'] }}</span>
        <span class="field field--key" style="left: 48.5%; top: 41.3%;">{{ $fields['businessId'] }}</span>
        <span class="field field--key" style="left: 80.3%; top: 41.3%;">{{ $fields['licenseNumber'] }}</span>

        {{-- LW Registry Number · Business Sector · City --}}
        <span class="field field--key" style="left: 21.8%; top: 43.9%;">{{ $fields['registryNumber'] }}</span>
        <span class="field field--tight" style="left: 44.9%; top: 43.9%;">{{ $fields['sector'] }}</span>
        <span class="field" style="left: 69.3%; top: 43.9%;">{{ $fields['city'] }}</span>

        {{-- State/Province · Country · Registration Date --}}
        <span class="field" style="left: 17.6%; top: 47.3%;">{{ $fields['state'] }}</span>
        <span class="field" style="left: 40.9%; top: 47.3%;">{{ $fields['country'] }}</span>
        <span class="field" style="left: 78.1%; top: 47.3%;">{{ $fields['registeredOn'] }}</span>

        {{-- Issue Date · Renewal Date --}}
        <span class="field" style="left: 15.4%; top: 49.9%;">{{ $fields['issuedOn'] }}</span>
        <span class="field" style="left: 44.0%; top: 49.9%;">{{ $fields['renewalOn'] }}</span>

        {{-- License Status · Membership Level --}}
        <span class="field field--status" style="left: 17.3%; top: 53.3%;">{{ $fields['status'] }}</span>
        <span class="field" style="left: 46.6%; top: 53.3%;">{{ $fields['membership'] }}</span>

        {{-- A real code over the artwork's placeholder, encoding this
             business's public verification URL. --}}
        @if ($qr)
            <span class="qr"><img src="{{ $qr }}" alt=""></span>
        @endif
    </div>
</body>
</html>
