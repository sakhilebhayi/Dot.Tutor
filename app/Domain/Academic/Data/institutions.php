<?php

/**
 * South Africa's 26 public universities (the Department of Higher Education
 * and Training's list). Names only, no addresses or websites: nothing is
 * seeded that the build agent could not stand behind. Schools are not seeded
 * and no school name is ever stored for a learner (POPIA: children's data is
 * minimised). Operators add TVET colleges and private institutions.
 *
 * @var list<array{name: string, short_name: ?string, kind: string}>
 */
return [
    ['name' => 'Cape Peninsula University of Technology', 'short_name' => 'CPUT', 'kind' => 'university_of_technology'],
    ['name' => 'Central University of Technology', 'short_name' => 'CUT', 'kind' => 'university_of_technology'],
    ['name' => 'Durban University of Technology', 'short_name' => 'DUT', 'kind' => 'university_of_technology'],
    ['name' => 'Mangosuthu University of Technology', 'short_name' => 'MUT', 'kind' => 'university_of_technology'],
    ['name' => 'Nelson Mandela University', 'short_name' => 'NMU', 'kind' => 'university'],
    ['name' => 'North-West University', 'short_name' => 'NWU', 'kind' => 'university'],
    ['name' => 'Rhodes University', 'short_name' => 'RU', 'kind' => 'university'],
    ['name' => 'Sefako Makgatho Health Sciences University', 'short_name' => 'SMU', 'kind' => 'university'],
    ['name' => 'Sol Plaatje University', 'short_name' => 'SPU', 'kind' => 'university'],
    ['name' => 'Stellenbosch University', 'short_name' => 'SU', 'kind' => 'university'],
    ['name' => 'Tshwane University of Technology', 'short_name' => 'TUT', 'kind' => 'university_of_technology'],
    ['name' => 'University of Cape Town', 'short_name' => 'UCT', 'kind' => 'university'],
    ['name' => 'University of Fort Hare', 'short_name' => 'UFH', 'kind' => 'university'],
    ['name' => 'University of the Free State', 'short_name' => 'UFS', 'kind' => 'university'],
    ['name' => 'University of Johannesburg', 'short_name' => 'UJ', 'kind' => 'university'],
    ['name' => 'University of KwaZulu-Natal', 'short_name' => 'UKZN', 'kind' => 'university'],
    ['name' => 'University of Limpopo', 'short_name' => 'UL', 'kind' => 'university'],
    ['name' => 'University of Mpumalanga', 'short_name' => 'UMP', 'kind' => 'university'],
    ['name' => 'University of Pretoria', 'short_name' => 'UP', 'kind' => 'university'],
    ['name' => 'University of South Africa', 'short_name' => 'Unisa', 'kind' => 'university'],
    ['name' => 'University of Venda', 'short_name' => 'Univen', 'kind' => 'university'],
    ['name' => 'University of the Western Cape', 'short_name' => 'UWC', 'kind' => 'university'],
    ['name' => 'University of the Witwatersrand', 'short_name' => 'Wits', 'kind' => 'university'],
    ['name' => 'University of Zululand', 'short_name' => 'UniZulu', 'kind' => 'university'],
    ['name' => 'Vaal University of Technology', 'short_name' => 'VUT', 'kind' => 'university_of_technology'],
    ['name' => 'Walter Sisulu University', 'short_name' => 'WSU', 'kind' => 'university'],
];
