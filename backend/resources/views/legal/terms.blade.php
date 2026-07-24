@extends('legal.layout')

@section('title', 'Termeni si conditii')

@section('content')
    <section>
        <h2>1. Introducere</h2>
        <p>
            Prezentii Termeni si conditii reglementeaza utilizarea aplicatiei mobile {{ $companyName }}
            si a serviciilor de incarcare pentru vehicule electrice operate de {{ $companyName }}.
            Prin crearea contului sau autentificare confirmi ca ai citit, ai inteles si accepti acesti termeni.
        </p>
    </section>

    <section>
        <h2>2. Contul utilizatorului</h2>
        <ul>
            <li>Trebuie sa furnizezi date reale si sa pastrezi confidentialitatea parolei.</li>
            <li>Esti responsabil pentru activitatea desfasurata din contul tau.</li>
            <li>Ne rezervam dreptul de a suspenda conturi folosite abuziv, fraudulos sau in incalcarea legii.</li>
        </ul>
    </section>

    <section>
        <h2>3. Servicii de incarcare si plati</h2>
        <ul>
            <li>Pretul energiei este afisat in aplicatie inainte de pornirea sesiunii.</li>
            <li>Soldul contului trebuie sa acopera consumul estimat.</li>
            <li>Alimentarea soldului se face prin metode de plata aprobate (card bancar).</li>
            <li>Facturile si istoricul tranzactiilor sunt disponibile in aplicatie.</li>
        </ul>
    </section>

    <section>
        <h2>4. Rezervari</h2>
        <p>
            Rezervarile de conector sunt gratuite si limitate la durata maxima afisata in aplicatie.
            Neprezentarea la timp poate duce la anularea rezervarii. Respecta instructiunile statiei
            si ale personalului de suport.
        </p>
    </section>

    <section>
        <h2>5. Utilizare acceptabila</h2>
        <ul>
            <li>Nu deteriora echipamentele statiei sau cablurile de incarcare.</li>
            <li>Nu folosi aplicatia pentru activitati ilegale sau pentru a afecta functionarea retelei.</li>
            <li>Respecta regulile de parcare si acces de la locatia statiei.</li>
        </ul>
    </section>

    <section>
        <h2>6. Limitarea raspunderii</h2>
        <p>
            Facem eforturi rezonabile pentru disponibilitatea statiilor, insa nu garantam functionarea
            neintrerupta a retelei sau a aplicatiei. {{ $companyName }} nu raspunde pentru pierderi
            indirecte rezultate din indisponibilitate temporara, exceptii prevazute de lege.
        </p>
    </section>

    <section>
        <h2>7. Modificari si contact</h2>
        <p>
            Putem actualiza acesti termeni. Versiunea curenta este indicata in aplicatie.
            Continuarea utilizarii dupa publicarea unei versiuni noi presupune acceptarea actualizarii.
            Intrebari: <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
        </p>
    </section>
@endsection
