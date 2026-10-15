<?php

// Mesaje de validare afisate in aplicatie si in backoffice (APP_LOCALE=ro).
// Regulile netraduse aici cad pe engleza (fallback_locale).
return [
    'accepted' => 'Trebuie sa accepti :attribute.',
    'after_or_equal' => ':Attribute trebuie sa fie o data egala sau ulterioara cu :date.',
    'between' => [
        'numeric' => ':Attribute trebuie sa fie intre :min si :max.',
        'string' => ':Attribute trebuie sa aiba intre :min si :max caractere.',
        'array' => ':Attribute trebuie sa aiba intre :min si :max elemente.',
        'file' => ':Attribute trebuie sa aiba intre :min si :max kilobytes.',
    ],
    'boolean' => ':Attribute trebuie sa fie adevarat sau fals.',
    'confirmed' => 'Confirmarea pentru :attribute nu coincide.',
    'date' => ':Attribute nu este o data valida.',
    'date_format' => ':Attribute nu respecta formatul :format.',
    'email' => ':Attribute trebuie sa fie o adresa de e-mail valida.',
    'exists' => ':Attribute selectat nu exista.',
    'in' => ':Attribute selectat nu este valid.',
    'integer' => ':Attribute trebuie sa fie un numar intreg.',
    'max' => [
        'numeric' => ':Attribute nu poate depasi :max.',
        'string' => ':Attribute nu poate avea mai mult de :max caractere.',
        'array' => ':Attribute nu poate avea mai mult de :max elemente.',
        'file' => ':Attribute nu poate depasi :max kilobytes.',
    ],
    'min' => [
        'numeric' => ':Attribute trebuie sa fie cel putin :min.',
        'string' => ':Attribute trebuie sa aiba cel putin :min caractere.',
        'array' => ':Attribute trebuie sa aiba cel putin :min elemente.',
        'file' => ':Attribute trebuie sa aiba cel putin :min kilobytes.',
    ],
    'not_regex' => ':Attribute nu are un format valid.',
    'numeric' => ':Attribute trebuie sa fie un numar.',
    'password' => [
        'letters' => ':Attribute trebuie sa contina cel putin o litera.',
        'mixed' => ':Attribute trebuie sa contina cel putin o litera mare si una mica.',
        'numbers' => ':Attribute trebuie sa contina cel putin o cifra.',
        'symbols' => ':Attribute trebuie sa contina cel putin un simbol.',
        'uncompromised' => ':Attribute a aparut intr-o scurgere de date. Alege alta parola.',
    ],
    'required' => 'Campul :attribute este obligatoriu.',
    'required_without_all' => 'Campul :attribute este obligatoriu.',
    'string' => ':Attribute trebuie sa fie text.',
    'unique' => 'Exista deja un cont cu acest :attribute.',

    'attributes' => [
        'email' => 'e-mail',
        'password' => 'parola',
        'name' => 'nume si prenume',
        'first_name' => 'prenume',
        'last_name' => 'nume',
        'phone' => 'telefon',
        'amount' => 'suma',
        'accept_terms' => 'Termenii si Politica de confidentialitate',
        'budget_amount' => 'suma',
        'target_kwh' => 'cantitatea de kWh',
        'connector_id' => 'portul',
        'station_id' => 'statia',
    ],
];
