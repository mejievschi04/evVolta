@extends('legal.layout')

@section('title', 'Politica de confidentialitate')

@section('content')
    <section>
        <h2>1. Operatorul datelor</h2>
        <p>
            {{ $companyName }} este operatorul datelor personale colectate prin aplicatia mobila
            si serviciile de incarcare EV. Pentru solicitari legate de date ne poti contacta la
            <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
        </p>
    </section>

    <section>
        <h2>2. Ce date colectam</h2>
        <ul>
            <li>Date de cont: nume, e-mail, parola (stocata criptat).</li>
            <li>Date de utilizare: sesiuni de incarcare, consum kWh, statii folosite, rezervari.</li>
            <li>Date de plata: tranzactii de alimentare sold, facturi (fara stocarea completă a datelor cardului).</li>
            <li>Date tehnice: identificatori de sesiune, jurnal de erori, adresa IP la autentificare.</li>
        </ul>
    </section>

    <section>
        <h2>3. De ce prelucram datele</h2>
        <ul>
            <li>Furnizarea serviciului de incarcare si gestionarea contului.</li>
            <li>Facturare, conformitate fiscala si suport clienti.</li>
            <li>Securitate, prevenirea fraudei si imbunatatirea aplicatiei.</li>
            <li>Respectarea obligatiilor legale aplicabile in Republica Moldova.</li>
        </ul>
    </section>

    <section>
        <h2>4. Temeiul legal</h2>
        <p>
            Prelucrarea se bazeaza pe executarea contractului (furnizarea serviciului),
            interesul legitim (securitate si functionare) si, unde este cazul, obligatii legale.
            Acceptarea Termenilor si a acestei Politici este necesara pentru utilizarea aplicatiei.
        </p>
    </section>

    <section>
        <h2>5. Partajarea datelor</h2>
        <p>
            Nu vindem datele tale. Le putem transmite doar furnizorilor necesari functionarii serviciului
            (procesatori de plati, hosting, suport tehnic) si autoritatilor, daca legea o impune.
            Transferurile se fac cu masuri de protectie adecvate.
        </p>
    </section>

    <section>
        <h2>6. Perioada de stocare</h2>
        <p>
            Pastram datele cat timp contul este activ si ulterior doar pe perioadele necesare
            obligatiilor legale, contabilitatii sau solutionarii litigiilor. Poti solicita stergerea
            contului din aplicatie, cu respectarea restrictiilor legale (sold zero, fara sesiune activa).
        </p>
    </section>

    <section>
        <h2>7. Drepturile tale</h2>
        <ul>
            <li>Acces, rectificare si stergere a datelor, in limitele legii.</li>
            <li>Restrictarea sau opozitia fata de anumite prelucrari.</li>
            <li>Portabilitatea datelor furnizate de tine, unde este aplicabil.</li>
            <li>Depunerea unei plangeri la autoritatea de supraveghere competenta.</li>
        </ul>
    </section>

    <section>
        <h2>8. Securitate si actualizari</h2>
        <p>
            Aplicam masuri tehnice si organizatorice rezonabile pentru protectia datelor.
            Aceasta politica poate fi actualizata; versiunea curenta este afisata in aplicatie.
            Utilizarea continuata dupa o actualizare inseamna acceptarea noii versiuni.
        </p>
    </section>
@endsection
