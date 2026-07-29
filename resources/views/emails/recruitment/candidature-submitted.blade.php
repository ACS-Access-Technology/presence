<p>Une candidature vient d'être {{ $candidature->statut->label() }} pour le poste « {{ $candidature->test->titre }} ».</p>
<ul>
    <li>Candidat : {{ $candidature->prenom }} {{ $candidature->nom }} ({{ $candidature->email }})</li>
    <li>Score : {{ $candidature->score }} / {{ $candidature->test->questions()->count() * 3 }}</li>
</ul>
<p>Consultez le détail dans Presence, rubrique Recrutement.</p>
