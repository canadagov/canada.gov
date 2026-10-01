<?php
declare(strict_types=1);

/**
 * An easter egg: ask how to help the Leafs win the Stanley Cup and the answer
 * is a poem, then a photo of 1967. No model is called and nothing is searched.
 */

// The photo is shown only if this file exists. It is not in the repository:
// photos of the 1967 win are in copyright, so add one you have a licence for
// and name its owner in LEAFS_PHOTO_CREDIT.
const LEAFS_PHOTO = 'assets/leafs-1967.jpg';
const LEAFS_PHOTO_CREDIT = '';

const LEAFS_CAPTION = [
    'en' => 'May 1967: the last time the Leafs brought the Stanley Cup home.',
    'fr' => 'Mai 1967 : la dernière fois que les Leafs ont ramené la coupe Stanley à la maison.',
];

const LEAFS_POEM = [
    'en' => <<<POEM
        ### The Ballad of the Blue and White

        You asked how to help the Leafs win the Cup.
        I read every page the government put up.
        No form can be filed, no office will call,
        and no federal program brings hope in the fall.

        In the spring of the country’s centennial year,
        Keon and Armstrong made Carlton Street cheer.
        They beat Montreal in six, and then
        brought the Cup home, and never again.

        Since then there’s the high stick the referee missed,
        the four-to-one lead that slipped out of their fist,
        the night a Zamboni driver in net
        beat them at home. We’re not over it yet.

        Each autumn the faithful say, “This is the year.”
        Each spring a Game Seven, and then it is clear.
        The parade route is planned, the plans put away.
        The children of sixty-seven are grey.

        So how can you help? There is nothing to send.
        Wear the blue sweater and cheer to the end,
        and believe, as this city has always done.
        It was nineteen sixty-seven, the last time they won.
        POEM,
    'fr' => <<<POEM
        ### La complainte des Bleu et Blanc

        Vous demandez comment aider les Leafs à gagner la Coupe.
        J’ai lu chaque page officielle à la loupe.
        Aucun formulaire, aucun bureau,
        aucun programme fédéral pour un tel fardeau.

        Au printemps du centenaire, en soixante-sept,
        Keon et Armstrong étaient de la fête.
        Montréal battu en six matchs, et puis
        la Coupe est rentrée. Plus rien depuis.

        Depuis : le bâton élevé que l’arbitre n’a pas vu,
        l’avance de quatre à un, perdue,
        et ce soir où un conducteur de Zamboni, devant le filet,
        les a battus chez eux. On ne s’en est jamais remis tout à fait.

        Chaque automne, les fidèles disent : « C’est la bonne année. »
        Chaque printemps, un septième match, et tout est terminé.
        Le trajet du défilé est tracé, puis rangé.
        Les enfants de soixante-sept ont les cheveux argentés.

        Alors, comment aider? Il n’y a rien à envoyer.
        Portez le chandail bleu, continuez d’encourager,
        et gardez la foi, comme la ville l’a toujours gardée.
        C’était en 1967, la dernière fois qu’ils ont gagné.
        POEM,
];

/** Whether the question is about the Leafs and the Cup, in either language. */
function is_leafs_cup_question(string $question): bool
{
    return preg_match('/\bleafs\b/iu', $question) === 1
        && preg_match('/\b(stanley|cup|coupe)\b/iu', $question) === 1;
}

/** The photo as ['src', 'alt', 'credit', 'width', 'height'], or null if there is no file. */
function leafs_photo(string $lang): ?array
{
    $size = @getimagesize(__DIR__ . '/../public/' . LEAFS_PHOTO);
    return $size ? [
        'src' => LEAFS_PHOTO,
        'alt' => LEAFS_CAPTION[$lang],
        'credit' => LEAFS_PHOTO_CREDIT,
        'width' => $size[0],
        'height' => $size[1],
    ] : null;
}
