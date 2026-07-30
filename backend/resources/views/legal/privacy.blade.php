@extends('legal.layout')

@section('title', 'Politica de confidentialitate')

@section('lede')
Aceasta Politica explica ce date personale prelucram prin aplicatia mobila
<strong>{{ $appName }}</strong>, operate de <strong>{{ $companyName }}</strong>,
temeiurile legale, perioadele de stocare, drepturile tale (inclusiv export si stergere)
si cum ne poti contacta. Se citeste impreuna cu Termenii si conditiile.
@endsection

@section('toc')
    <ol>
        <li><a href="#p1"><span class="n">1.</span>Operatorul datelor</a></li>
        <li><a href="#p2"><span class="n">2.</span>Ce date colectam</a></li>
        <li><a href="#p3"><span class="n">3.</span>Scopuri si temeiuri legale</a></li>
        <li><a href="#p4"><span class="n">4.</span>Permisiuni pe dispozitiv</a></li>
        <li><a href="#p5"><span class="n">5.</span>Partajarea datelor</a></li>
        <li><a href="#p6"><span class="n">6.</span>Perioade de stocare</a></li>
        <li><a href="#p7"><span class="n">7.</span>Drepturile tale</a></li>
        <li><a href="#p8"><span class="n">8.</span>Securitate si anonimizare</a></li>
        <li><a href="#p9"><span class="n">9.</span>Actualizari si contact</a></li>
    </ol>
@endsection

@section('content')
    <section class="legal-section" id="p1">
        <h2><span class="n">1.</span> Operatorul datelor</h2>
        <p>
            <strong>{{ $companyName }}</strong> este operatorul datelor personale colectate prin
            aplicatia mobila <strong>{{ $appName }}</strong> si serviciile de incarcare EV.
            Pentru solicitari legate de date ne poti contacta la
            <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>
            @if(!empty($supportPhone))
                sau la {{ $supportPhone }}
            @endif
            .
        </p>
        <p>
            Autoritatea de supraveghere competenta:
            <strong>{{ $authority['name'] ?? 'CNPD' }}</strong>
            @if(!empty($authority['url']))
                (<a href="{{ $authority['url'] }}">{{ $authority['url'] }}</a>)
            @endif
            @if(!empty($authority['email']))
                · <a href="mailto:{{ $authority['email'] }}">{{ $authority['email'] }}</a>
            @endif
            .
        </p>
    </section>

    <section class="legal-section" id="p2">
        <h2><span class="n">2.</span> Ce date colectam</h2>
        <ul>
            <li><strong>Date de cont:</strong> nume, e-mail, telefon (optional), parola (hash), tip de cont, sold.</li>
            <li><strong>Date de utilizare:</strong> sesiuni de incarcare, consum kWh, putere, statii
                si conectori folositi, rezervari, bugete de incarcare, favorite.</li>
            <li><strong>Date de plata:</strong> tranzactii de alimentare sold, status plati, facturi,
                retururi. Nu stocam numarul complet al cardului.</li>
            <li><strong>Date tehnice:</strong> jurnale de autentificare, erori, identificatori de sesiune,
                date necesare functionarii OCPP si a suportului.</li>
            <li><strong>Acceptari legale:</strong> data, versiunea Termenilor/Politicii, IP si user-agent
                la momentul acceptarii (dovada consimtamantului/acceptarii).</li>
        </ul>
        <p>Nu folosim publicitate comportamentala, tracking SDK-uri de marketing sau web scraping pe datele tale.</p>
    </section>

    <section class="legal-section" id="p3">
        <h2><span class="n">3.</span> Scopuri si temeiuri legale</h2>
        <ul>
            <li><strong>Furnizarea Serviciului</strong> (cont, incarcare, rezervari, facturi) —
                executarea contractului.</li>
            <li><strong>Plati si sold</strong> — executarea contractului si obligatii legale fiscale.</li>
            <li><strong>Securitate, fraud prevention, jurnale tehnice</strong> — interes legitim,
                cu masuri proportionale.</li>
            <li><strong>Acceptarea Termenilor/Politicii</strong> — necesara pentru utilizarea aplicatiei;
                re-acceptarea este ceruta la versiuni noi.</li>
            <li><strong>Obligatii legale</strong> (contabilitate, facturi) — temei legal.</li>
        </ul>
    </section>

    <section class="legal-section" id="p4">
        <h2><span class="n">4.</span> Permisiuni pe dispozitiv</h2>
        <ul>
            <li><strong>Locatie:</strong> {{ $devicePermissions['location'] ?? 'Afisarea statiilor pe harta.' }}</li>
            <li><strong>Camera:</strong> {{ $devicePermissions['camera'] ?? 'Scanare QR.' }}</li>
        </ul>
        <p>Poti revoca aceste permisiuni din setarile sistemului de operare; unele functii pot deveni indisponibile.</p>
    </section>

    <section class="legal-section" id="p5">
        <h2><span class="n">5.</span> Partajarea datelor</h2>
        <p>Nu vindem datele tale. Le putem transmite doar:</p>
        <ul>
            @foreach($processors as $processor)
                <li>
                    <strong>{{ $processor['name'] ?? 'Procesator' }}</strong>
                    — {{ $processor['purpose'] ?? '' }}
                    @if(!empty($processor['location']))
                        ({{ $processor['location'] }})
                    @endif
                </li>
            @endforeach
            <li>autoritatilor, daca legea o impune.</li>
        </ul>
        <p>Transferurile se fac cu masuri de protectie adecvate (contracte, acces restrictionat, HTTPS).</p>
    </section>

    <section class="legal-section" id="p6">
        <h2><span class="n">6.</span> Perioade de stocare</h2>
        <ul>
            <li>Date de cont: pe durata contului activ.</li>
            <li>Facturi / evidenta fiscala: aproximativ {{ $retention['invoices_years'] ?? 7 }} ani.</li>
            <li>Sesiuni de incarcare: pana la {{ $retention['charging_sessions_days'] ?? 2555 }} zile.</li>
            <li>Rezervari inchise: {{ $retention['reservations_days'] ?? 730 }} zile.</li>
            <li>Jurnale audit: {{ $retention['audit_logs_days'] ?? 730 }} zile.</li>
            <li>Mesaje OCPP tehnice: {{ $retention['ocpp_messages_days'] ?? 90 }} zile.</li>
        </ul>
        <p>
            La stergerea contului, datele de identificare sunt <strong>anonimizate</strong>.
            Evidentele fiscale pot fi pastrate in forma anonimizata pe perioadele cerute de lege.
        </p>
    </section>

    <section class="legal-section" id="p7">
        <h2><span class="n">7.</span> Drepturile tale</h2>
        <ul>
            <li>Acces si portabilitate — export JSON din aplicatie.</li>
            <li>Rectificare — din Setari cont.</li>
            <li>Stergere / anonimizare — din Setari cont (cu restrictii operationale: sold zero, fara sesiune activa, fara facturi neplatite).</li>
            <li>Restrictare / opozitie — la cerere pe e-mail, unde legea permite.</li>
            <li>Plangere la autoritatea de supraveghere.</li>
        </ul>
        <p>
            In aplicatie: <strong>Setari cont → Confidentialitate</strong> pentru Termeni, Politica
            si <strong>Exporta datele mele</strong>. Raspundem la cereri in maxim
            <strong>{{ $rightsSlaDays }}</strong> zile.
        </p>
        <div class="callout">
            <strong>Contact DPO / privacy:</strong>
            <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>
        </div>
    </section>

    <section class="legal-section" id="p8">
        <h2><span class="n">8.</span> Securitate si anonimizare</h2>
        <p>
            Aplicam masuri tehnice si organizatorice (HTTPS, JWT, hashing parole, control acces,
            jurnale, CSP pe documente legale). La stergere, e-mailul, telefonul si numele sunt
            inlocuite cu valori anonime; autentificarea pe contul sters nu mai este posibila.
        </p>
        <p>
            Conform ghidurilor EDPB privind anonimizarea (2026), tratam datele pastrate dupa
            stergere ca evidenta operationala/fiscala fara identificare directa, si reevaluam
            periodic riscul de re-identificare pentru datele agregate.
        </p>
    </section>

    <section class="legal-section" id="p9">
        <h2><span class="n">9.</span> Actualizari si contact</h2>
        <p>
            Aceasta politica poate fi actualizata; versiunea curenta ({{ $legalVersion }},
            in vigoare din {{ $effectiveDate }}) este afisata in aplicatie. La modificari
            semnificative solicitam o noua acceptare explicita.
        </p>
        <p>
            Contact: <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>
            @if(!empty($supportPhone))
                · {{ $supportPhone }}
            @endif
            · Operator: <strong>{{ $companyName }}</strong>
            · Aplicatie: <strong>{{ $appName }}</strong>.
        </p>
    </section>
@endsection
