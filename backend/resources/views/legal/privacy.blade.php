@extends('legal.layout')

@section('title', 'Politica de confidentialitate')

@section('lede')
Aceasta Politica explica ce date personale prelucram prin aplicatia mobila
<strong>{{ $appName }}</strong>, operate de <strong>{{ $companyName }}</strong>,
de ce le folosim, cui le putem transmite si ce drepturi ai. Se citeste impreuna cu
Termenii si conditiile.
@endsection

@section('toc')
    <ol>
        <li><a href="#p1"><span class="n">1.</span>Operatorul datelor</a></li>
        <li><a href="#p2"><span class="n">2.</span>Ce date colectam</a></li>
        <li><a href="#p3"><span class="n">3.</span>Scopurile prelucrarii</a></li>
        <li><a href="#p4"><span class="n">4.</span>Temeiul legal</a></li>
        <li><a href="#p5"><span class="n">5.</span>Partajarea datelor</a></li>
        <li><a href="#p6"><span class="n">6.</span>Perioada de stocare</a></li>
        <li><a href="#p7"><span class="n">7.</span>Drepturile tale</a></li>
        <li><a href="#p8"><span class="n">8.</span>Securitate</a></li>
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
    </section>

    <section class="legal-section" id="p2">
        <h2><span class="n">2.</span> Ce date colectam</h2>
        <ul>
            <li><strong>Date de cont:</strong> nume, e-mail, parola (stocata criptat / hash), tip de cont.</li>
            <li><strong>Date de utilizare:</strong> sesiuni de incarcare, consum kWh, putere, statii
                si conectori folositi, rezervari, bugete de incarcare.</li>
            <li><strong>Date de plata:</strong> tranzactii de alimentare sold, status plati, facturi,
                retururi. Nu stocam numarul complet al cardului.</li>
            <li><strong>Date tehnice:</strong> jurnale de autentificare, erori, identificatori de sesiune,
                date necesare functionarii OCPP si a suportului.</li>
            <li><strong>Acceptari legale:</strong> data si versiunea Termenilor / Politicii acceptate.</li>
        </ul>
    </section>

    <section class="legal-section" id="p3">
        <h2><span class="n">3.</span> Scopurile prelucrarii</h2>
        <ul>
            <li>Furnizarea serviciului de incarcare, rezervari si gestionarea contului.</li>
            <li>Procesarea platilor, soldului, facturarii si a retururilor.</li>
            <li>Suport clienti, securitate, prevenirea fraudei si imbunatatirea aplicatiei.</li>
            <li>Respectarea obligatiilor legale aplicabile in Republica Moldova.</li>
        </ul>
    </section>

    <section class="legal-section" id="p4">
        <h2><span class="n">4.</span> Temeiul legal</h2>
        <p>
            Prelucrarea se bazeaza pe executarea contractului (furnizarea Serviciului),
            interesul legitim (securitate, functionare, imbunatatiri) si, unde este cazul,
            obligatii legale. Acceptarea Termenilor si a acestei Politici este necesara
            pentru utilizarea aplicatiei.
        </p>
    </section>

    <section class="legal-section" id="p5">
        <h2><span class="n">5.</span> Partajarea datelor</h2>
        <p>
            Nu vindem datele tale. Le putem transmite doar:
        </p>
        <ul>
            <li>procesatorilor de plati (de exemplu pentru alimentare sold / retururi);</li>
            <li>furnizorilor de hosting, infrastructura si suport tehnic, in limitele necesare;</li>
            <li>autoritatilor, daca legea o impune.</li>
        </ul>
        <p>Transferurile se fac cu masuri de protectie adecvate.</p>
    </section>

    <section class="legal-section" id="p6">
        <h2><span class="n">6.</span> Perioada de stocare</h2>
        <p>
            Pastram datele cat timp contul este activ si ulterior pe perioadele necesare
            obligatiilor legale, contabilitatii sau solutionarii litigiilor. Poti solicita
            stergerea contului din aplicatie, cu respectarea restrictiilor operationale
            (de exemplu sold zero, fara sesiune activa, fara facturi neplatite).
        </p>
    </section>

    <section class="legal-section" id="p7">
        <h2><span class="n">7.</span> Drepturile tale</h2>
        <ul>
            <li>Acces, rectificare si stergere a datelor, in limitele legii.</li>
            <li>Restrictarea sau opozitia fata de anumite prelucrari.</li>
            <li>Portabilitatea datelor furnizate de tine, unde este aplicabil.</li>
            <li>Depunerea unei plangeri la autoritatea de supraveghere competenta.</li>
        </ul>
        <div class="callout">
            <strong>Cum exerciti drepturile:</strong> scrie-ne la
            <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>. Vom raspunde in termenele
            prevazute de lege.
        </div>
    </section>

    <section class="legal-section" id="p8">
        <h2><span class="n">8.</span> Securitate</h2>
        <p>
            Aplicam masuri tehnice si organizatorice rezonabile (inclusiv autentificare,
            control acces, jurnale) pentru protectia datelor. Nicio transmisie pe internet
            nu este 100% sigura; te rugam sa folosesti o parola puternica si sa nu o partajezi.
        </p>
    </section>

    <section class="legal-section" id="p9">
        <h2><span class="n">9.</span> Actualizari si contact</h2>
        <p>
            Aceasta politica poate fi actualizata; versiunea curenta ({{ $legalVersion }})
            este afisata in aplicatie. Utilizarea continua dupa o actualizare semnificativa
            poate necesita o noua acceptare.
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
