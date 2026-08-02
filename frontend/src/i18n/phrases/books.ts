// Books & Periodicals plugin phrases: the ISBN catalogue, the credits on a
// cover, and the book fields on the product form.
//
// 'Publishers', 'Authors' and 'Book Languages' are menu rows and live in
// menuLabels.ts, not here — a second copy is a duplicate key and fails the
// type build.
import type { PhraseFile } from './types'

const phrases: PhraseFile = {
  // ---- Titles screen ----
  'Book Titles': { hi: 'पुस्तक शीर्षक', gu: 'પુસ્તક શીર્ષકો' },
  'The catalogue by ISBN, house, language and the people on the cover': {
    hi: 'ISBN, प्रकाशक, भाषा और कवर पर के लोगों के अनुसार सूची',
    gu: 'ISBN, પ્રકાશક, ભાષા અને કવર પરના લોકો પ્રમાણે યાદી',
  },
  'Scan or type an ISBN — 978-93-5136-557-0': {
    hi: 'ISBN स्कैन करें या टाइप करें — 978-93-5136-557-0',
    gu: 'ISBN સ્કેન કરો અથવા ટાઇપ કરો — 978-93-5136-557-0',
  },
  'Look up': { hi: 'खोजें', gu: 'શોધો' },
  'Show in list': { hi: 'सूची में दिखाएँ', gu: 'યાદીમાં બતાવો' },
  'No credits recorded': { hi: 'कोई श्रेय दर्ज नहीं', gu: 'કોઈ શ્રેય નોંધાયેલ નથી' },
  'Title, ISBN, series or SKU': {
    hi: 'शीर्षक, ISBN, शृंखला या SKU',
    gu: 'શીર્ષક, ISBN, શ્રેણી અથવા SKU',
  },
  'Author or translator': { hi: 'लेखक या अनुवादक', gu: 'લેખક અથવા અનુવાદક' },
  'Binding': { hi: 'बाइंडिंग', gu: 'બાઇન્ડિંગ' },
  'Credits': { hi: 'श्रेय', gu: 'શ્રેય' },
  'Credits saved': { hi: 'श्रेय सहेजे गए', gu: 'શ્રેય સાચવ્યા' },
  'Could not save the credits': { hi: 'श्रेय सहेजे नहीं जा सके', gu: 'શ્રેય સાચવી શકાયા નહીં' },
  'Add a credit': { hi: 'श्रेय जोड़ें', gu: 'શ્રેય ઉમેરો' },
  'Nobody credited yet.': { hi: 'अभी किसी को श्रेय नहीं दिया गया।', gu: 'હજી કોઈને શ્રેય અપાયો નથી.' },
  'Person': { hi: 'व्यक्ति', gu: 'વ્યક્તિ' },
  'The order here is the billing on the cover. One person may appear twice in two different roles — an author who also illustrated the book, for instance.': {
    hi: 'यहाँ का क्रम कवर पर छपे क्रम जैसा है। एक ही व्यक्ति दो अलग भूमिकाओं में दो बार आ सकता है — जैसे वह लेखक जिसने चित्र भी बनाए।',
    gu: 'અહીંનો ક્રમ કવર પર છપાયેલા ક્રમ જેવો છે. એક જ વ્યક્તિ બે અલગ ભૂમિકામાં બે વાર આવી શકે — જેમ કે લેખક જેણે ચિત્રો પણ દોર્યાં.',
  },
  // The barcode has stopped being the ISBN — the failure nothing on the shelf
  // shows, so the words have to carry it.
  'Barcode is': { hi: 'बारकोड है', gu: 'બારકોડ છે' },
  'the scanner will not find this': {
    hi: 'स्कैनर इसे नहीं खोज पाएगा',
    gu: 'સ્કેનર આને શોધી શકશે નહીં',
  },
  'Printed': { hi: 'मुद्रित', gu: 'છાપેલ' },
  'No titles yet. A book becomes a title as soon as it is given an ISBN, a publisher or a language on the Products screen.': {
    hi: 'अभी कोई शीर्षक नहीं। उत्पाद स्क्रीन पर ISBN, प्रकाशक या भाषा देते ही पुस्तक शीर्षक बन जाती है।',
    gu: 'હજી કોઈ શીર્ષક નથી. પ્રોડક્ટ સ્ક્રીન પર ISBN, પ્રકાશક કે ભાષા આપતાં જ પુસ્તક શીર્ષક બની જાય છે.',
  },

  // ---- Book fields on the product form ----
  'ISBN-13': { hi: 'ISBN-13', gu: 'ISBN-13' },
  'ISBN-10': { hi: 'ISBN-10', gu: 'ISBN-10' },
  'Type it as printed. Hyphens are stripped, the check digit is verified, and the number becomes the barcode.': {
    hi: 'जैसा छपा है वैसा टाइप करें। हाइफ़न हटा दिए जाते हैं, चेक अंक जाँचा जाता है, और यही नंबर बारकोड बन जाता है।',
    gu: 'જેમ છપાયું છે તેમ ટાઇપ કરો. હાઇફન દૂર થાય છે, ચેક અંક ચકાસાય છે, અને આ જ નંબર બારકોડ બને છે.',
  },
  'Only for older titles. Given one, the 13-digit form is worked out from it.': {
    hi: 'केवल पुरानी पुस्तकों के लिए। यह देने पर 13-अंकों वाला रूप इसी से निकाल लिया जाता है।',
    gu: 'ફક્ત જૂનાં પુસ્તકો માટે. આ આપતાં 13-અંકનું સ્વરૂપ આમાંથી જ કાઢી લેવાય છે.',
  },
  'No publisher': { hi: 'कोई प्रकाशक नहीं', gu: 'કોઈ પ્રકાશક નહીં' },
  'No language': { hi: 'कोई भाषा नहीं', gu: 'કોઈ ભાષા નહીં' },
  'Not stated': { hi: 'नहीं बताया गया', gu: 'જણાવ્યું નથી' },
  'Printed price': { hi: 'मुद्रित मूल्य', gu: 'છાપેલ કિંમત' },
  'The price on the cover. The selling price above is what this shop charges.': {
    hi: 'कवर पर छपा मूल्य। ऊपर का विक्रय मूल्य वह है जो यह दुकान लेती है।',
    gu: 'કવર પર છપાયેલી કિંમત. ઉપરની વેચાણ કિંમત એ છે જે આ દુકાન લે છે.',
  },
  'Edition': { hi: 'संस्करण', gu: 'આવૃત્તિ' },
  '3rd edition, revised': { hi: 'तीसरा संस्करण, संशोधित', gu: 'ત્રીજી આવૃત્તિ, સુધારેલી' },
  'Pages': { hi: 'पृष्ठ', gu: 'પાનાં' },
  'Published on': { hi: 'प्रकाशन तिथि', gu: 'પ્રકાશન તારીખ' },
  'Series': { hi: 'शृंखला', gu: 'શ્રેણી' },
  'Volume': { hi: 'खंड', gu: 'ખંડ' },
  'A customer who finished volume three asks for volume four by the series name.': {
    hi: 'तीसरा खंड पढ़ चुका ग्राहक चौथा खंड शृंखला के नाम से माँगता है।',
    gu: 'ત્રીજો ખંડ વાંચી ચૂકેલો ગ્રાહક ચોથો ખંડ શ્રેણીના નામથી માંગે છે.',
  },
}

export default phrases
