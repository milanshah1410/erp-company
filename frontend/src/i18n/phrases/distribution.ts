// Distribution & Wholesale plugin screens: Credit Control and Outstanding &
// Ageing (app/Modules/Industry/Distribution).
//
// The vocabulary is a wholesaler's, not a translator's: a party (पार्टी) is the
// shop being supplied, a beat (बीट) is the route a salesman walks, and "stopped"
// is what a despatch clerk says about a party whose supply is held — so those are
// the words used rather than literal renderings of "customer" and "on hold".
import type { PhraseFile } from './types'

const phrases: PhraseFile = {
  // --- Credit Control ---
  'Credit Control': { hi: 'क्रेडिट नियंत्रण', gu: 'ક્રેડિટ કંટ્રોલ' },
  'Party limits against live exposure — and who does not get goods today': {
    hi: 'पार्टी की लिमिट बनाम मौजूदा जोखिम — और आज किसे माल नहीं जाएगा',
    gu: 'પાર્ટીની લિમિટ સામે વર્તમાન જોખમ — અને આજે કોને માલ નહીં જાય',
  },
  'parties': { hi: 'पार्टियाँ', gu: 'પાર્ટીઓ' },
  'Parties': { hi: 'पार्टियाँ', gu: 'પાર્ટીઓ' },
  'Confirmed, not invoiced': { hi: 'कन्फर्म, बिल नहीं बना', gu: 'કન્ફર્મ, બિલ બન્યું નથી' },
  'Confirmed, not yet invoiced': { hi: 'कन्फर्म, बिल अभी नहीं बना', gu: 'કન્ફર્મ, બિલ હજી બન્યું નથી' },
  'not counted against limits': { hi: 'लिमिट में नहीं गिना जाता', gu: 'લિમિટમાં ગણાતું નથી' },
  'Over limit': { hi: 'लिमिट से ऊपर', gu: 'લિમિટથી વધુ' },
  'Stopped': { hi: 'रोका गया', gu: 'રોકેલું' },
  'Too old': { hi: 'बहुत पुराना', gu: 'ઘણું જૂનું' },
  'Near limit': { hi: 'लिमिट के करीब', gu: 'લિમિટની નજીક' },
  'OK': { hi: 'ठीक', gu: 'ઠીક' },
  'Standing': { hi: 'स्थिति', gu: 'સ્થિતિ' },

  'Despatch is blocked when a party is over their limit.': {
    hi: 'पार्टी लिमिट पार कर जाए तो डिस्पैच रोक दिया जाता है।',
    gu: 'પાર્ટી લિમિટ વટાવે તો ડિસ્પેચ રોકાય છે.',
  },
  'Limits are advisory: over-limit parties are flagged but still supplied.': {
    hi: 'लिमिट सलाह मात्र है: लिमिट पार करने वाली पार्टी चिह्नित होती है, माल फिर भी जाता है।',
    gu: 'લિમિટ સલાહરૂપ છે: લિમિટ વટાવનારી પાર્ટી ચિહ્નિત થાય છે, માલ તો જાય છે.',
  },
  'Automatic limit checks are off. Manual holds still stop supply.': {
    hi: 'स्वतः लिमिट जाँच बंद है। हाथ से लगाई रोक फिर भी माल रोकती है।',
    gu: 'સ્વયં લિમિટ ચકાસણી બંધ છે. હાથે લગાવેલી રોક તો માલ રોકે છે.',
  },
  'Supply also stops past {n} days overdue.': {
    hi: '{n} दिन से ज़्यादा बकाया होने पर भी माल रुकता है।',
    gu: '{n} દિવસથી વધુ બાકી હોય તો પણ માલ રોકાય છે.',
  },
  'Default terms: {n} days': { hi: 'डिफ़ॉल्ट अवधि: {n} दिन', gu: 'ડિફૉલ્ટ મુદત: {n} દિવસ' },

  'Party name, code or phone': { hi: 'पार्टी का नाम, कोड या फ़ोन', gu: 'પાર્ટીનું નામ, કોડ કે ફોન' },
  'Beat / route': { hi: 'बीट / मार्ग', gu: 'બીટ / રૂટ' },
  'Town': { hi: 'शहर', gu: 'શહેર' },
  'Include settled parties': { hi: 'निपटी पार्टियाँ भी दिखाएँ', gu: 'પતાવેલી પાર્ટીઓ પણ બતાવો' },

  'No limit': { hi: 'कोई लिमिट नहीं', gu: 'કોઈ લિમિટ નથી' },
  'Exposure': { hi: 'कुल जोखिम', gu: 'કુલ જોખમ' },
  'incl. orders': { hi: 'ऑर्डर सहित', gu: 'ઓર્ડર સહિત' },
  'Available': { hi: 'उपलब्ध', gu: 'ઉપલબ્ધ' },
  '{n} days late': { hi: '{n} दिन देर', gu: '{n} દિવસ મોડું' },
  'd': { hi: 'दि', gu: 'દિ' },

  'Stop supply': { hi: 'माल रोकें', gu: 'માલ રોકો' },
  'Resume supply': { hi: 'माल फिर शुरू करें', gu: 'માલ ફરી શરૂ કરો' },
  'Edit limit and terms': { hi: 'लिमिट और अवधि बदलें', gu: 'લિમિટ અને મુદત બદલો' },
  'Ageing detail': { hi: 'एजिंग विवरण', gu: 'એજિંગ વિગત' },
  'Despatch is blocked for this party': { hi: 'इस पार्टी का डिस्पैच रोका गया है', gu: 'આ પાર્ટીનું ડિસ્પેચ રોકેલું છે' },
  'Supply stopped': { hi: 'माल रोक दिया गया', gu: 'માલ રોકી દેવાયો' },
  'Supply resumed': { hi: 'माल फिर शुरू', gu: 'માલ ફરી શરૂ' },
  'Terms updated': { hi: 'अवधि अपडेट हुई', gu: 'મુદત અપડેટ થઈ' },
  'No party has anything outstanding, committed or held.': {
    hi: 'किसी पार्टी का न बकाया है, न ऑर्डर लंबित, न रोक लगी है।',
    gu: 'કોઈ પાર્ટીનું બાકી નથી, ઓર્ડર બાકી નથી, રોક પણ નથી.',
  },
  'No goods will be despatched to {name} until this is released — at the order desk, the counter and the despatch door alike.': {
    hi: 'रोक हटने तक {name} को कोई माल नहीं जाएगा — ऑर्डर डेस्क, काउंटर और डिस्पैच गेट, तीनों जगह।',
    gu: 'રોક હટે ત્યાં સુધી {name} ને કોઈ માલ નહીં જાય — ઓર્ડર ડેસ્ક, કાઉન્ટર અને ડિસ્પેચ ગેટ, ત્રણેય જગ્યાએ.',
  },
  'Reason': { hi: 'कारण', gu: 'કારણ' },
  'Shown to whoever tries to supply them.': {
    hi: 'जो कोई माल भेजने की कोशिश करेगा, उसे यही दिखेगा।',
    gu: 'જે કોઈ માલ મોકલવાનો પ્રયાસ કરશે તેને આ જ દેખાશે.',
  },

  'Limit and terms': { hi: 'लिमिट और अवधि', gu: 'લિમિટ અને મુદત' },
  'Credit limit': { hi: 'क्रेडिट लिमिट', gu: 'ક્રેડિટ લિમિટ' },
  '0 means no limit is enforced for this party.': {
    hi: '0 का अर्थ है इस पार्टी पर कोई लिमिट लागू नहीं।',
    gu: '0 એટલે આ પાર્ટી પર કોઈ લિમિટ લાગુ નથી.',
  },
  'Credit period (days)': { hi: 'क्रेडिट अवधि (दिन)', gu: 'ક્રેડિટ મુદત (દિવસ)' },
  '0 follows the workspace default of {n} days. Applies to invoices raised from now on.': {
    hi: '0 पर वर्कस्पेस की डिफ़ॉल्ट {n} दिन की अवधि चलेगी। अब से बनने वाले चालानों पर लागू।',
    gu: '0 હોય તો વર્કસ્પેસની ડિફૉલ્ટ {n} દિવસની મુદત ચાલશે. હવે પછી બનતાં બિલો પર લાગુ.',
  },

  'Credit policy': { hi: 'क्रेडिट नीति', gu: 'ક્રેડિટ નીતિ' },
  'Credit policy saved': { hi: 'क्रेडिट नीति सहेजी गई', gu: 'ક્રેડિટ નીતિ સાચવી' },
  'When a party is over their limit': { hi: 'जब पार्टी लिमिट पार कर जाए', gu: 'જ્યારે પાર્ટી લિમિટ વટાવે' },
  'Block the despatch': { hi: 'डिस्पैच रोकें', gu: 'ડિસ્પેચ રોકો' },
  'Warn only': { hi: 'केवल चेतावनी', gu: 'ફક્ત ચેતવણી' },
  'Off (holds still apply)': { hi: 'बंद (रोक फिर भी लागू)', gu: 'બંધ (રોક તો લાગુ)' },
  'A manual hold always stops supply, whatever this is set to.': {
    hi: 'हाथ से लगाई रोक हर हाल में माल रोकती है, यह सेटिंग कुछ भी हो।',
    gu: 'હાથે લગાવેલી રોક દરેક સ્થિતિમાં માલ રોકે છે, આ સેટિંગ ભલે કંઈ પણ હોય.',
  },
  'Also stop supply past this many days overdue': {
    hi: 'इतने दिन से ज़्यादा बकाया होने पर भी माल रोकें',
    gu: 'આટલા દિવસથી વધુ બાકી હોય તો પણ માલ રોકો',
  },
  '0 turns the age check off.': { hi: '0 से यह जाँच बंद हो जाती है।', gu: '0 થી આ ચકાસણી બંધ થાય છે.' },
  'Count confirmed, uninvoiced orders against the limit': {
    hi: 'कन्फर्म पर बिना बिल वाले ऑर्डर भी लिमिट में गिनें',
    gu: 'કન્ફર્મ પણ બિલ વગરના ઓર્ડર પણ લિમિટમાં ગણો',
  },
  'On for a distributor releasing lorries daily; off for a counter where the order and the bill are the same moment.': {
    hi: 'रोज़ गाड़ी छोड़ने वाले वितरक के लिए चालू रखें; जहाँ ऑर्डर और बिल एक ही पल हैं, उस काउंटर के लिए बंद।',
    gu: 'રોજ ગાડી છોડતા વિતરક માટે ચાલુ રાખો; જ્યાં ઓર્ડર અને બિલ એક જ ક્ષણે થાય એવા કાઉન્ટર માટે બંધ.',
  },

  // --- Ageing, shared by both screens ---
  'Outstanding & Ageing': { hi: 'बकाया और एजिंग', gu: 'બાકી અને એજિંગ' },
  'Party-wise receivables by days past due, sliced by salesman, beat and town': {
    hi: 'पार्टीवार बकाया, देरी के दिनों के हिसाब से — सेल्समैन, बीट और शहर के अनुसार',
    gu: 'પાર્ટીવાર બાકી, મોડાના દિવસો પ્રમાણે — સેલ્સમેન, બીટ અને શહેર અનુસાર',
  },
  'Total outstanding': { hi: 'कुल बकाया', gu: 'કુલ બાકી' },
  '{n}% of the book': { hi: 'बही का {n}%', gu: 'ચોપડાનું {n}%' },
  'Over 90 days': { hi: '90 दिन से ऊपर', gu: '90 દિવસથી વધુ' },
  'Not yet due': { hi: 'अभी देय नहीं', gu: 'હજી બાકી નથી' },
  'Not due': { hi: 'देय नहीं', gu: 'બાકી નથી' },
  'Not Due': { hi: 'देय नहीं', gu: 'બાકી નથી' },
  '1-30': { hi: '1-30', gu: '1-30' },
  '31-60': { hi: '31-60', gu: '31-60' },
  '61-90': { hi: '61-90', gu: '61-90' },
  '90+': { hi: '90+', gu: '90+' },
  '1-30 days': { hi: '1-30 दिन', gu: '1-30 દિવસ' },
  '31-60 days': { hi: '31-60 दिन', gu: '31-60 દિવસ' },
  '61-90 days': { hi: '61-90 दिन', gu: '61-90 દિવસ' },
  '90+ days': { hi: '90+ दिन', gu: '90+ દિવસ' },
  'As of': { hi: 'तारीख तक', gu: 'તારીખ સુધી' },
  'Min amount': { hi: 'न्यूनतम राशि', gu: 'ન્યૂનતમ રકમ' },
  'Overdue only': { hi: 'केवल बकाया', gu: 'ફક્ત બાકી' },
  'Opening': { hi: 'प्रारंभिक', gu: 'શરૂઆતનું' },
  'Opening balance': { hi: 'प्रारंभिक शेष', gu: 'શરૂઆતની બાકી' },
  'Days late': { hi: 'देरी के दिन', gu: 'મોડાના દિવસ' },
  'Flags': { hi: 'चिह्न', gu: 'ચિહ્નો' },
  'Totals': { hi: 'कुल', gu: 'કુલ' },
  'days': { hi: 'दिन', gu: 'દિવસ' },
  'Due': { hi: 'देय', gu: 'બાકી' },
  'Invoice value': { hi: 'चालान मूल्य', gu: 'બિલ રકમ' },
  'Open invoices': { hi: 'खुले चालान', gu: 'ખુલ્લાં બિલ' },
  'Nothing unpaid.': { hi: 'कुछ बकाया नहीं।', gu: 'કંઈ બાકી નથી.' },
  'Nothing outstanding for these filters.': {
    hi: 'इन फ़िल्टरों पर कोई बकाया नहीं।',
    gu: 'આ ફિલ્ટરો પર કંઈ બાકી નથી.',
  },
  'No open invoices — the balance is a carried-forward opening figure.': {
    hi: 'कोई खुला चालान नहीं — यह शेष पिछली बही से आया प्रारंभिक आँकड़ा है।',
    gu: 'કોઈ ખુલ્લું બિલ નથી — આ બાકી પાછલા ચોપડામાંથી આવેલો શરૂઆતનો આંકડો છે.',
  },
  'Buckets count days past the invoice due date. An opening balance carries no invoice and therefore no age, so it is shown separately and only added into the total.': {
    hi: 'खाने चालान की देय तिथि के बाद के दिन गिनते हैं। प्रारंभिक शेष के पीछे कोई चालान नहीं होता, इसलिए उसकी कोई उम्र नहीं — वह अलग दिखता है और सिर्फ़ कुल में जुड़ता है।',
    gu: 'ખાનાં બિલની બાકી તારીખ પછીના દિવસ ગણે છે. શરૂઆતની બાકી પાછળ કોઈ બિલ નથી, તેથી તેની કોઈ ઉંમર નથી — તે અલગ દેખાય છે અને ફક્ત કુલમાં ઉમેરાય છે.',
  },
  'Order': { hi: 'ऑर्डर', gu: 'ઓર્ડર' },
  'Value': { hi: 'मूल्य', gu: 'રકમ' },
}

export default phrases
