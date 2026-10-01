/**
 * The drawing on a preset's card in the layout picker.
 *
 * One drawing per preset, not one drawing parameterised by the preset. The
 * generated version read the template and drew rails, tiles and lines from
 * what it found, which was honest but illegible: twelve layouts came out as
 * the same stack of bars, and a merchant picked by name rather than by
 * picture. These are drawn to be told apart at 130px -- a Classic row has a
 * face and stars beside the name, Compact stacks four of them tight, a photo
 * layout is unmistakably photographs.
 *
 * The cost of drawing them by hand is that a new preset needs a new drawing;
 * without one it falls back to plain lines rather than to a wrong picture.
 */

// Flat, low-contrast greys so the drawing reads as a wireframe and never
// competes with the preset's name under it. Stars are the one warm accent,
// photographs the one cool one -- the two things worth spotting at a glance.
const PANEL = '#eef1f7';
const CARD = '#ffffff';
const BAR = '#ccd1db';
const SOFT = '#dfe3ea';
const RULE = '#e6e9ef';
const STAR = '#f2b134';
const PHOTO = '#becffa';
const ACCENT = '#3b5bdb';

const VIEW_W = 120;
const VIEW_H = 66;

const Bar = ({x, y, w, h = 3, fill = SOFT}) => (
    <rect x={x} y={y} width={w} height={h} rx={h / 2} fill={fill}/>
);

const Dot = ({cx, cy, r = 4, fill = BAR}) => <circle cx={cx} cy={cy} r={r} fill={fill}/>;

const Card = ({x, y, w, h}) => <rect x={x} y={y} width={w} height={h} rx={3} fill={CARD}/>;

const Photo = ({x, y, w, h, rx = 2}) => <rect x={x} y={y} width={w} height={h} rx={rx} fill={PHOTO}/>;

/* A review row: face, name, stars, then what they wrote. */
const Row = ({y, width = 84}) => (
    <>
        <Dot cx={14} cy={y + 4} r={5}/>
        <Bar x={23} y={y + 2} w={22} h={4} fill={BAR}/>
        <Bar x={49} y={y + 2} w={18} h={4} fill={STAR}/>
        <Bar x={71} y={y + 2.5} w={36}/>
        <Bar x={23} y={y + 11} w={width}/>
    </>
);

/* A card in a grid or a slider: stars, then a couple of lines of words. */
const WordCard = ({x, y, w, h}) => (
    <>
        <Card x={x} y={y} w={w} h={h}/>
        <Bar x={x + 4} y={y + 5} w={16} h={4} fill={STAR}/>
        <Bar x={x + 4} y={y + 13} w={w - 8}/>
        {h > 20 && <Bar x={x + 4} y={y + 19} w={w - 14}/>}
    </>
);

const drawings = {
    // Two full reviews, each with a face, a name, stars and a sentence.
    classic: (
        <>
            <Row y={10}/>
            <Row y={38} width={62}/>
        </>
    ),

    // No faces and no cards: names and sentences, ruled off from each other.
    minimal: (
        <>
            <Bar x={9} y={10} w={38} h={4} fill={BAR}/>
            <Bar x={9} y={18} w={102}/>
            <rect x={9} y={24} width={102} height={1} fill={RULE}/>
            <Bar x={9} y={29} w={30} h={4} fill={BAR}/>
            <Bar x={9} y={37} w={92}/>
            <rect x={9} y={43} width={102} height={1} fill={RULE}/>
            <Bar x={9} y={48} w={34} h={4} fill={BAR}/>
            <Bar x={9} y={56} w={102}/>
        </>
    ),

    // Four reviews to the space Classic gives two, each on a single line.
    compact: (
        <>
            {[13, 26, 39, 52].map((y) => (
                <g key={y}>
                    <Dot cx={13} cy={y} r={3.5}/>
                    <Bar x={21} y={y - 2} w={22} h={4} fill={BAR}/>
                    <Bar x={48} y={y - 1.5} w={59}/>
                </g>
            ))}
        </>
    ),

    // The average and the star breakdown in a card across the top.
    'summary-top': (
        <>
            <Card x={8} y={6} w={104} h={30}/>
            <rect x={13} y={11} width={14} height={10} rx={2} fill={BAR}/>
            <Bar x={32} y={11} w={67} h={5} fill={STAR}/>
            <Bar x={13} y={25} w={14} h={4} fill={STAR}/>
            <Bar x={32} y={25} w={30} h={4}/>
            <Bar x={8} y={42} w={44} h={4} fill={BAR}/>
            <Bar x={8} y={51} w={104}/>
            <Bar x={8} y={58} w={78}/>
        </>
    ),

    // Three to a row, two rows, every card the same height.
    grid: (
        <>
            {[[8, 8], [44, 8], [80, 8], [8, 36], [44, 36], [80, 36]].map(([x, y]) => (
                <g key={`${x}-${y}`}><WordCard x={x} y={y} w={32} h={24}/></g>
            ))}
        </>
    ),

    // The same cards, but nothing lines up -- that is the whole point of it.
    masonry: (
        <>
            <WordCard x={8} y={8} w={32} h={26}/>
            <WordCard x={8} y={38} w={32} h={19}/>
            <WordCard x={44} y={8} w={32} h={19}/>
            <WordCard x={44} y={31} w={32} h={26}/>
            <WordCard x={80} y={8} w={32} h={30}/>
            <WordCard x={80} y={42} w={32} h={15}/>
        </>
    ),

    // Two at a time, with dots underneath saying there are more.
    carousel: (
        <>
            {[8, 62].map((x) => (
                <g key={x}>
                    <Card x={x} y={8} w={50} h={34}/>
                    <Dot cx={x + 10} cy={18} r={4}/>
                    <Bar x={x + 18} y={15} w={22} h={5} fill={STAR}/>
                    <Bar x={x + 6} y={27} w={38}/>
                    <Bar x={x + 6} y={33} w={28}/>
                </g>
            ))}
            <Bar x={48} y={50} w={9} h={3.5} fill={BAR}/>
            <Dot cx={63} cy={51.5} r={1.8} fill={SOFT}/>
            <Dot cx={70} cy={51.5} r={1.8} fill={SOFT}/>
        </>
    ),

    // The quotation mark, the headline, the quote, then who said it -- centred,
    // which is the order and the arrangement the preset actually builds.
    testimonials: (
        <>
            <text x={60} y={26} textAnchor="middle" fontSize="26" fontFamily="Georgia, serif" fill={ACCENT} opacity="0.3">&#8220;</text>
            <Bar x={38} y={24} w={44} h={4} fill={BAR}/>
            <Bar x={22} y={33} w={76}/>
            <Bar x={32} y={40} w={56}/>
            <Dot cx={46} cy={53} r={4}/>
            <Bar x={54} y={51} w={20} h={4} fill={STAR}/>
        </>
    ),

    // Three to a row, the photograph edge to edge across the top of the card.
    // Drawn as the card's own top corners rather than as a picture set inside
    // it, because that is the whole difference the flush setting makes.
    'photo-grid': (
        <>
            {[8, 44, 80].map((x) => (
                <g key={x}>
                    <Card x={x} y={6} w={32} h={54}/>
                    <path
                        d={`M${x} 9a3 3 0 0 1 3-3h26a3 3 0 0 1 3 3v22H${x}z`}
                        fill={PHOTO}
                    />
                    <Bar x={x + 4} y={37} w={16} h={4} fill={STAR}/>
                    <Bar x={x + 4} y={45} w={24}/>
                    <Bar x={x + 4} y={52} w={18}/>
                </g>
            ))}
        </>
    ),

    // The photograph is the card; the stars and the name sit over its foot.
    'photo-wall': (
        <>
            {[8, 44, 80].map((x) => (
                <g key={x}>
                    <Photo x={x} y={9} w={32} h={48} rx={3}/>
                    <Bar x={x + 5} y={38} w={14} h={4} fill={STAR}/>
                    <Bar x={x + 5} y={46} w={22} h={3.5} fill={CARD}/>
                </g>
            ))}
        </>
    ),

    // A band of customer photographs that slides, above a list rather than
    // instead of one.
    'photo-strip': (
        <>
            {[9.5, 30.5, 51.5, 72.5, 93.5].map((x) => (
                <g key={x}><Photo x={x} y={10} w={17} h={46} rx={3}/></g>
            ))}
        </>
    ),
};

// A preset with no drawing of its own, and `custom`, which by definition has
// no picture to draw. Plain lines say "a layout" without claiming which.
const fallback = (
    <>
        <Bar x={9} y={16} w={102}/>
        <Bar x={9} y={31} w={84}/>
        <Bar x={9} y={46} w={62}/>
    </>
);

const PresetThumb = ({value}) => (
    <svg
        className="fct-review-preset-icon"
        viewBox={`0 0 ${VIEW_W} ${VIEW_H}`}
        preserveAspectRatio="xMidYMid meet"
        aria-hidden="true"
        focusable="false"
    >
        <rect x="0" y="0" width={VIEW_W} height={VIEW_H} rx="4" fill={PANEL}/>
        {drawings[value] || fallback}
    </svg>
);

export default PresetThumb;
