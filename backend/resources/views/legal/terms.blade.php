@extends('legal.layout')

@section('title', 'Termeni si conditii')

@section('lede')
Acesti Termeni reglementeaza utilizarea aplicatiei mobile <strong>{{ $appName }}</strong>
si a serviciilor de incarcare pentru vehicule electrice operate de
<strong>{{ $companyName }}</strong>. Te rugam sa ii citesti cu atentie inainte de a crea
un cont sau de a porni o sesiune.
@endsection

@section('toc')
    <ol>
        <li><a href="#s1"><span class="n">1.</span>Partile si obiectul</a></li>
        <li><a href="#s2"><span class="n">2.</span>Acceptarea termenilor</a></li>
        <li><a href="#s3"><span class="n">3.</span>Contul utilizatorului</a></li>
        <li><a href="#s4"><span class="n">4.</span>Serviciul de incarcare</a></li>
        <li><a href="#s5"><span class="n">5.</span>Tarife si preturi</a></li>
        <li><a href="#s6"><span class="n">6.</span>Sold, plati si retururi</a></li>
        <li><a href="#s7"><span class="n">7.</span>Facturare</a></li>
        <li><a href="#s8"><span class="n">8.</span>Rezervari</a></li>
        <li><a href="#s9"><span class="n">9.</span>Utilizare acceptabila</a></li>
        <li><a href="#s10"><span class="n">10.</span>Disponibilitate</a></li>
        <li><a href="#s11"><span class="n">11.</span>Limitarea raspunderii</a></li>
        <li><a href="#s12"><span class="n">12.</span>Proprietate intelectuala</a></li>
        <li><a href="#s13"><span class="n">13.</span>Stergerea contului</a></li>
        <li><a href="#s14"><span class="n">14.</span>Modificari</a></li>
        <li><a href="#s15"><span class="n">15.</span>Legea aplicabila</a></li>
        <li><a href="#s16"><span class="n">16.</span>Contact</a></li>
    </ol>
@endsection

@section('content')
    <section class="legal-section" id="s1">
        <h2><span class="n">1.</span> Partile si obiectul</h2>
        <p>
            Prezentii Termeni si conditii („<strong>Termenii</strong>”) constituie acordul dintre
            <strong>{{ $companyName }}</strong> („noi”, „operatorul”) si tine, ca utilizator al
            aplicatiei mobile <strong>{{ $appName }}</strong> si al retelei de statii de incarcare
            pentru vehicule electrice („<strong>Serviciul</strong>”).
        </p>
        <p>
            Serviciul include, dupa caz: crearea si gestionarea contului, localizarea statiilor,
            rezervarea conectorilor, pornirea si oprirea sesiunilor de incarcare, alimentarea
            soldului, urmarirea consumului (kWh), facturarea si istoricul tranzactiilor.
        </p>
        <div class="callout">
            <strong>Important:</strong> Politica de confidentialitate face parte din cadrul legal
            al Serviciului si se citeste impreuna cu acesti Termeni.
        </div>
    </section>

    <section class="legal-section" id="s2">
        <h2><span class="n">2.</span> Acceptarea termenilor</h2>
        <p>
            Prin crearea contului, autentificare sau utilizarea continua a aplicatiei, confirmi ca:
        </p>
        <ul>
            <li>ai cel putin 18 ani sau capacitate legala de a incheia un contract;</li>
            <li>ai citit si accepti acesti Termeni si Politica de confidentialitate;</li>
            <li>datele furnizate sunt corecte si actualizate.</li>
        </ul>
        <p>
            Daca nu esti de acord, nu utiliza aplicatia si nu creea un cont.
            Versiunea aplicabila este cea afisata in aplicatie (versiune {{ $legalVersion }}).
        </p>
    </section>

    <section class="legal-section" id="s3">
        <h2><span class="n">3.</span> Contul utilizatorului</h2>
        <p>
            Contul este personal. Pot exista tipuri de cont (de exemplu <strong>Utilizator</strong>
            sau <strong>Personal</strong>), cu reguli de tarifare si plata diferite, afisate in aplicatie.
        </p>
        <ul>
            <li>Pastrezi confidentialitatea parolei si a dispozitivului pe care esti autentificat.</li>
            <li>Esti responsabil pentru activitatea desfasurata din contul tau.</li>
            <li>Ne anunti fara intarziere daca suspectezi acces neautorizat.</li>
            <li>Ne rezervam dreptul de a suspenda sau restrictiona conturi folosite abuziv,
                fraudulos, cu date false sau in incalcarea legii ori a acestor Termeni.</li>
        </ul>
    </section>

    <section class="legal-section" id="s4">
        <h2><span class="n">4.</span> Serviciul de incarcare</h2>
        <p>
            Statiile sunt operate prin protocoale de comunicare dedicate (inclusiv OCPP).
            Pentru a porni o sesiune, urmeaza fluxul din aplicatie: selecteaza statia/conectorul,
            conecteaza cablul conform instructiunilor si confirma pornirea.
        </p>
        <ul>
            <li>Incarcarea poate necesita detectarea corecta a conectorului (plug) inainte de start.</li>
            <li>Poti opri sesiunea din aplicatie; oprirea poate avea loc si la epuizarea bugetului,
                la tinta de energie setata, din motive tehnice sau din motive de siguranta.</li>
            <li>Consumul (kWh), puterea si costul estimat/final sunt calculate pe baza datelor
                primite de la statie si a tarifului aplicabil contului tau.</li>
            <li>Esti responsabil sa eliberezi conectorul si locatia dupa finalizarea sesiunii.</li>
        </ul>
    </section>

    <section class="legal-section" id="s5">
        <h2><span class="n">5.</span> Tarife si preturi</h2>
        <p>
            Pretul energiei (lei/kWh) este afisat in aplicatie inainte sau in timpul utilizarii
            Serviciului. Tarifele pot diferi intre tipurile de cont si pot fi actualizate de operator.
        </p>
        <ul>
            <li>Costul unei sesiuni se bazeaza pe energia livrata si, daca este cazul, pe alte
                componente comunicate in aplicatie.</li>
            <li>Afisajele in timp real sunt estimative; valoarea finala rezulta dupa inchiderea sesiunii.</li>
            <li>Moneda de referinta a Serviciului este <strong>MDL (leu moldovenesc)</strong>,
                daca nu este indicat altfel.</li>
        </ul>
    </section>

    <section class="legal-section" id="s6">
        <h2><span class="n">6.</span> Sold, plati si retururi</h2>
        <p>
            Pentru conturile care folosesc plata cu cardul, Serviciul poate functiona pe baza unui
            <strong>sold preplatit</strong> (wallet). Inainte de incarcare, soldul trebuie sa acopere
            consumul estimat sau bugetul ales in aplicatie.
        </p>
        <ul>
            <li>Alimentarea soldului se face prin metode de plata aprobate (de exemplu card bancar
                prin procesatorul de plati integrat).</li>
            <li>Datele complete ale cardului nu sunt stocate de {{ $companyName }}; plata este
                procesata de furnizorul de plati conform politicii acestuia.</li>
            <li>Tranzactiile apar in istoricul din aplicatie; statusul „platit” / „in asteptare”
                reflecta starea confirmata de procesator.</li>
            <li>Retururile (partiale sau totale) pot fi initiate conform politicilor noastre si
                ale procesatorului, in limitele sumelor eligibile si ale soldului disponibil.</li>
            <li>Taxele bancare sau ale procesatorului, daca apar, pot fi in afara controlului nostru.</li>
        </ul>
    </section>

    <section class="legal-section" id="s7">
        <h2><span class="n">7.</span> Facturare</h2>
        <p>
            Facturile si documentele fiscale asociate sesiunilor sau alimentarilor sunt disponibile
            in aplicatie, acolo unde Serviciul le genereaza. Pastreaza-le pentru evidenta personala.
            Pentru corectii sau contestatii, contacteaza-ne la
            <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a> in termen rezonabil
            de la emitere.
        </p>
    </section>

    <section class="legal-section" id="s8">
        <h2><span class="n">8.</span> Rezervari</h2>
        <p>
            Poti rezerva un conector la statiile care ofera aceasta functie. Rezervarile sunt
            <strong>gratuite</strong> si limitate la durata maxima configurata pentru statie
            (de regula pana la <strong>30 de minute</strong>), afisata in aplicatie.
        </p>
        <ul>
            <li>Rezervarea iti pastreaza conectorul pe intervalul confirmat, in limitele tehnice
                ale statiei.</li>
            <li>Neprezentarea, intarzierea peste perioada de gratie sau incalcarea regulilor
                poate duce la anularea automata a rezervarii.</li>
            <li>Poti anula o rezervare din aplicatie, daca statusul permite.</li>
            <li>Rezervarea nu garanteaza pornirea incarcarii daca exista defectiuni, ocupare
                neasteptata sau nerespectarea procedurii de conectare.</li>
        </ul>
    </section>

    <section class="legal-section" id="s9">
        <h2><span class="n">9.</span> Utilizare acceptabila</h2>
        <p>Te angajezi sa:</p>
        <ul>
            <li>nu deteriorezi echipamentele, cablurile, ecranele sau infrastructura statiei;</li>
            <li>nu incerci sa ocolesti securitatea, autentificarea sau sistemele de plata;</li>
            <li>nu folosesti Serviciul pentru activitati ilegale sau pentru a perturba reteaua;</li>
            <li>respecti regulile de parcare, acces si siguranta de la locatia statiei;</li>
            <li>nu blochezi inutil conectorii sau locurile de parcare rezervate pentru EV.</li>
        </ul>
        <p>
            Incalcarea acestor reguli poate atrage suspendarea contului, anularea rezervarilor
            si, daca este cazul, actiuni legale sau recuperarea prejudiciului.
        </p>
    </section>

    <section class="legal-section" id="s10">
        <h2><span class="n">10.</span> Disponibilitate</h2>
        <p>
            Depunem eforturi rezonabile pentru functionarea aplicatiei si a statiilor, dar nu
            garantam disponibilitate neintrerupta. Pot aparea intreruperi din cauze precum:
            mentenanta, defectiuni hardware, probleme de retea/OCPP, lucrari la locatie,
            evenimente de forta majora sau actiuni ale tertilor.
        </p>
        <p>
            Informatiile despre statusul statiilor din harta pot avea intarzieri fata de starea
            reala de pe teren.
        </p>
    </section>

    <section class="legal-section" id="s11">
        <h2><span class="n">11.</span> Limitarea raspunderii</h2>
        <p>
            In masura permisa de legea aplicabila, {{ $companyName }} nu raspunde pentru:
        </p>
        <ul>
            <li>pierderi indirecte, de oportunitate sau de profit rezultate din indisponibilitate
                temporara a Serviciului;</li>
            <li>daune cauzate de utilizarea necorespunzatoare a conectorului, a cablului sau a
                vehiculului tau;</li>
            <li>erori ale procesatorilor de plata, ale bancii emitente sau ale retelelor tertilor;</li>
            <li>continutul sau actiunile utilizatorilor care incalca acesti Termeni.</li>
        </ul>
        <p>
            Nimic din acesti Termeni nu exclude raspunderea care nu poate fi limitata legal
            (de exemplu in caz de dol sau culpa grava, unde legea o impune).
        </p>
    </section>

    <section class="legal-section" id="s12">
        <h2><span class="n">12.</span> Proprietate intelectuala</h2>
        <p>
            Aplicatia <strong>{{ $appName }}</strong>, logo-urile, designul, textele si software-ul
            aferent Serviciului sunt protejate. Primesti o licenta limitata, neexclusiva si
            revocabila de a utiliza aplicatia doar pentru accesul la Serviciu. Nu ai dreptul sa
            copiezi, modifici, decompilezi sau redistribui aplicatia, cu exceptia cazurilor
            permise de lege.
        </p>
    </section>

    <section class="legal-section" id="s13">
        <h2><span class="n">13.</span> Stergerea contului</h2>
        <p>
            Poti solicita stergerea contului din setarile aplicatiei. Stergerea poate fi
            conditionata, de exemplu, de:
        </p>
        <ul>
            <li>absenta unei sesiuni de incarcare active;</li>
            <li>sold zero (sau solutionarea soldului pozitiv / obligatiilor deschise);</li>
            <li>absenta facturilor neplatite sau a altor blocaje operationale.</li>
        </ul>
        <p>
            Dupa confirmare, datele de identificare (nume, e-mail, telefon) sunt
            <strong>anonimizate</strong> si contul nu mai poate fi folosit. Evidentele fiscale
            pot fi pastrate in forma anonimizata pe perioadele cerute de lege, conform
            Politicii de confidentialitate. Poti exporta datele tale inainte de stergere
            din Setari cont → Confidentialitate.
        </p>
    </section>

    <section class="legal-section" id="s14">
        <h2><span class="n">14.</span> Modificari</h2>
        <p>
            Putem actualiza acesti Termeni pentru a reflecta modificari ale Serviciului
            (inclusiv rebranding-ul aplicatiei ca <strong>{{ $appName }}</strong>),
            ale legislatiei sau ale practicilor operationale. Versiunea curenta si data de
            intrare in vigoare sunt afisate in aplicatie si pe aceasta pagina.
        </p>
        <p>
            Daca modificarile sunt semnificative, putem solicita o noua acceptare la
            autentificare sau inainte de utilizarea continua. Continuarea utilizarii dupa
            publicarea unei versiuni noi, acolo unde legea o permite, poate constitui acceptare.
        </p>
    </section>

    <section class="legal-section" id="s15">
        <h2><span class="n">15.</span> Legea aplicabila</h2>
        <p>
            Acesti Termeni sunt guvernati de legislatia Republicii Moldova, daca nu este
            prevazut altfel de dispozitii imperative. Eventualele dispute vor fi solutionate
            pe cale amiabila; in caz contrar, de instantele competente din Republica Moldova,
            cu respectarea drepturilor consumatorilor.
        </p>
    </section>

    <section class="legal-section" id="s16">
        <h2><span class="n">16.</span> Contact</h2>
        <p>
            Pentru intrebari despre Termeni, facturare sau suport:
        </p>
        <ul>
            <li>E-mail: <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a></li>
            @if(!empty($supportPhone))
                <li>Telefon: {{ $supportPhone }}</li>
            @endif
            <li>Operator: <strong>{{ $companyName }}</strong> (aplicatia <strong>{{ $appName }}</strong>)</li>
        </ul>
    </section>
@endsection
