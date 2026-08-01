// Administration / settings / reports / AI screens.
// Populated as those views are converted to pt(); until then their English
// text renders as-is via the pt() fallback.
import type { PhraseFile } from './types'

const phrases: PhraseFile = {
  // --- My Profile ---
  'My Profile': { hi: 'मेरी प्रोफ़ाइल', gu: 'મારી પ્રોફાઇલ' },
  'Your name, contact details, photo and password.': {
    hi: 'आपका नाम, संपर्क विवरण, फ़ोटो और पासवर्ड।',
    gu: 'તમારું નામ, સંપર્ક વિગતો, ફોટો અને પાસવર્ડ.',
  },
  'Finish setting up your profile — add a phone number and a photo so your team recognises you.': {
    hi: 'अपनी प्रोफ़ाइल पूरी करें — फ़ोन नंबर और फ़ोटो जोड़ें ताकि आपकी टीम आपको पहचान सके।',
    gu: 'તમારી પ્રોફાઇલ પૂર્ણ કરો — ફોન નંબર અને ફોટો ઉમેરો જેથી તમારી ટીમ તમને ઓળખે.',
  },
  'Personal details': { hi: 'व्यक्तिगत विवरण', gu: 'વ્યક્તિગત વિગતો' },
  'Full name': { hi: 'पूरा नाम', gu: 'પૂરું નામ' },
  'Upload photo': { hi: 'फ़ोटो अपलोड करें', gu: 'ફોટો અપલોડ કરો' },
  'Change photo': { hi: 'फ़ोटो बदलें', gu: 'ફોટો બદલો' },
  'Photo updated': { hi: 'फ़ोटो अपडेट हुई', gu: 'ફોટો અપડેટ થયો' },
  'Photo upload failed': { hi: 'फ़ोटो अपलोड विफल', gu: 'ફોટો અપલોડ નિષ્ફળ' },
  'JPG/PNG up to 2MB.': { hi: 'JPG/PNG, 2MB तक।', gu: 'JPG/PNG, 2MB સુધી.' },
  'This is your login. Changing it needs re-verification.': {
    hi: 'यही आपका लॉगिन है। इसे बदलने पर पुनः सत्यापन आवश्यक है।',
    gu: 'આ તમારું લૉગિન છે. બદલવા પર ફરી ખાતરી કરવી પડશે.',
  },
  'Save changes': { hi: 'बदलाव सहेजें', gu: 'ફેરફારો સાચવો' },
  'Profile updated': { hi: 'प्रोफ़ाइल अपडेट हुई', gu: 'પ્રોફાઇલ અપડેટ થઈ' },
  'Could not load your profile': { hi: 'आपकी प्रोफ़ाइल लोड नहीं हो सकी', gu: 'તમારી પ્રોફાઇલ લોડ થઈ શકી નથી' },
  'Change password': { hi: 'पासवर्ड बदलें', gu: 'પાસવર્ડ બદલો' },
  'Current password': { hi: 'वर्तमान पासवर्ड', gu: 'હાલનો પાસવર્ડ' },
  'New password': { hi: 'नया पासवर्ड', gu: 'નવો પાસવર્ડ' },
  'Confirm new password': { hi: 'नया पासवर्ड पुष्टि करें', gu: 'નવો પાસવર્ડ ખાતરી કરો' },
  'Minimum 8 characters.': { hi: 'कम से कम 8 अक्षर।', gu: 'ઓછામાં ઓછા 8 અક્ષર.' },
  'Password changed': { hi: 'पासवर्ड बदल दिया गया', gu: 'પાસવર્ડ બદલાયો' },
  'Your access': { hi: 'आपकी पहुँच', gu: 'તમારો ઍક્સેસ' },
  'Email verified': { hi: 'ईमेल सत्यापित', gu: 'ઈમેલ ખાતરી થયેલ' },
  'Company details (GSTIN, address, financial year) live under': {
    hi: 'कंपनी विवरण (GSTIN, पता, वित्तीय वर्ष) यहाँ हैं:',
    gu: 'કંપની વિગતો (GSTIN, સરનામું, નાણાકીય વર્ષ) આ હેઠળ છે:',
  },

  // PrinterTestView
  'Printer Testing': { hi: 'प्रिंटर टेस्टिंग', gu: 'પ્રિન્ટર ટેસ્ટિંગ' },
  'Send a sample print of each template to check paper size, margins and alignment. Test prints are never saved as documents.': {
    hi: 'कागज़ का आकार, मार्जिन और अलाइनमेंट जाँचने के लिए हर टेम्पलेट का सैंपल प्रिंट भेजें। टेस्ट प्रिंट कभी दस्तावेज़ के रूप में सहेजे नहीं जाते।',
    gu: 'કાગળનું માપ, માર્જિન અને અલાઇનમેન્ટ ચકાસવા દરેક ટેમ્પ્લેટની સેમ્પલ પ્રિન્ટ મોકલો. ટેસ્ટ પ્રિન્ટ ક્યારેય દસ્તાવેજ તરીકે સાચવાતી નથી.',
  },
  'A4 Invoice': { hi: 'A4 इनवॉइस', gu: 'A4 ઇન્વૉઇસ' },
  'Thermal Invoice': { hi: 'थर्मल इनवॉइस', gu: 'થર્મલ ઇન્વૉઇસ' },
  'Barcode Label': { hi: 'बारकोड लेबल', gu: 'બારકોડ લેબલ' },
  'Full-page tax invoice on a laser/inkjet printer.': {
    hi: 'लेज़र/इंकजेट प्रिंटर पर पूरे पेज का टैक्स इनवॉइस।',
    gu: 'લેઝર/ઇંકજેટ પ્રિન્ટર પર આખા પાનાનું ટેક્સ ઇન્વૉઇસ.',
  },
  '58/80 mm POS receipt via browser or ESC/POS agent.': {
    hi: 'ब्राउज़र या ESC/POS एजेंट द्वारा 58/80 मि.मी. POS रसीद।',
    gu: 'બ્રાઉઝર અથવા ESC/POS એજન્ટ દ્વારા 58/80 મિ.મી. POS રસીદ.',
  },
  'Label sheet for the barcode/label printer.': {
    hi: 'बारकोड/लेबल प्रिंटर के लिए लेबल शीट।',
    gu: 'બારકોડ/લેબલ પ્રિન્ટર માટે લેબલ શીટ.',
  },
  'Prints the same tax-invoice template real invoices use, filled with sample data.': {
    hi: 'असली इनवॉइस वाला ही टैक्स-इनवॉइस टेम्पलेट, सैंपल डेटा के साथ प्रिंट होता है।',
    gu: 'સાચાં ઇન્વૉઇસ વાપરે છે તે જ ટેક્સ-ઇન્વૉઇસ ટેમ્પ્લેટ, સેમ્પલ ડેટા સાથે પ્રિન્ટ થાય છે.',
  },
  'Paper Size': { hi: 'कागज़ का आकार', gu: 'કાગળનું માપ' },
  'A4 (210 × 297 mm)': { hi: 'A4 (210 × 297 मि.मी.)', gu: 'A4 (210 × 297 મિ.મી.)' },
  'Letter (216 × 279 mm)': { hi: 'लेटर (216 × 279 मि.मी.)', gu: 'લેટર (216 × 279 મિ.મી.)' },
  'A5 (148 × 210 mm)': { hi: 'A5 (148 × 210 मि.मी.)', gu: 'A5 (148 × 210 મિ.મી.)' },
  'Sample Line Items': { hi: 'सैंपल लाइन आइटम', gu: 'સેમ્પલ લાઇન આઇટમ' },
  'Raise this past a page to test page breaks and the repeated footer.': {
    hi: 'पेज ब्रेक और हर पेज पर आने वाला फुटर जाँचने के लिए इसे एक पेज से ज़्यादा रखें।',
    gu: 'પેજ બ્રેક અને દરેક પાના પર આવતું ફૂટર ચકાસવા આને એક પાનાથી વધારે રાખો.',
  },
  "These are this terminal's printer settings — POS billing prints with exactly the same ones.": {
    hi: 'ये इसी टर्मिनल की प्रिंटर सेटिंग्स हैं — POS बिलिंग भी बिल्कुल इन्हीं से प्रिंट करती है।',
    gu: 'આ આ જ ટર્મિનલની પ્રિન્ટર સેટિંગ્સ છે — POS બિલિંગ પણ બરાબર આ જ થી પ્રિન્ટ કરે છે.',
  },
  'Test Print': { hi: 'टेस्ट प्रिंट', gu: 'ટેસ્ટ પ્રિન્ટ' },
  'Test Cash Drawer': { hi: 'कैश ड्रॉअर जाँचें', gu: 'કેશ ડ્રોઅર ચકાસો' },
  'Save Settings': { hi: 'सेटिंग्स सहेजें', gu: 'સેટિંગ્સ સાચવો' },
  'Reset to defaults': { hi: 'डिफ़ॉल्ट पर लौटाएँ', gu: 'ડિફૉલ્ટ પર પાછું લાવો' },
  'Printer settings saved': { hi: 'प्रिंटर सेटिंग्स सहेजी गईं', gu: 'પ્રિન્ટર સેટિંગ્સ સાચવાઈ' },
  'Sent to print agent': { hi: 'प्रिंट एजेंट को भेजा गया', gu: 'પ્રિન્ટ એજન્ટને મોકલ્યું' },
  'Drawer kick needs the local print agent.': {
    hi: 'ड्रॉअर खोलने के लिए लोकल प्रिंट एजेंट चाहिए।',
    gu: 'ડ્રોઅર ખોલવા માટે લોકલ પ્રિન્ટ એજન્ટ જરૂરી છે.',
  },
  'PRINTER TEST': { hi: 'प्रिंटर टेस्ट', gu: 'પ્રિન્ટર ટેસ્ટ' },
  'Prints a sheet of identical sample labels so label pitch and cut lines can be checked.': {
    hi: 'एक जैसे सैंपल लेबलों की शीट प्रिंट करता है, ताकि लेबल की दूरी और कट लाइन जाँची जा सके।',
    gu: 'સરખાં સેમ્પલ લેબલની શીટ પ્રિન્ટ કરે છે, જેથી લેબલનું અંતર અને કટ લાઇન ચકાસી શકાય.',
  },
  'Label Format': { hi: 'लेबल फ़ॉर्मेट', gu: 'લેબલ ફૉર્મેટ' },
  'Sample Code Value': { hi: 'सैंपल कोड वैल्यू', gu: 'સેમ્પલ કોડ વેલ્યુ' },
  'Number of Labels': { hi: 'लेबलों की संख्या', gu: 'લેબલની સંખ્યા' },
  'Could not generate the test print.': {
    hi: 'टेस्ट प्रिंट नहीं बन सका।',
    gu: 'ટેસ્ટ પ્રિન્ટ બની શકી નથી.',
  },
  'Hit Preview to render the template here, or Print to open it in a new tab.': {
    hi: 'टेम्पलेट यहीं देखने के लिए प्रीव्यू दबाएँ, या नए टैब में खोलने के लिए प्रिंट।',
    gu: 'ટેમ્પ્લેટ અહીં જ જોવા માટે પ્રીવ્યૂ દબાવો, અથવા નવા ટૅબમાં ખોલવા માટે પ્રિન્ટ.',
  },

  // --- Custom Fields (the field builder + the panel it renders) ---
  'Custom Fields': { hi: 'कस्टम फ़ील्ड', gu: 'કસ્ટમ ફીલ્ડ' },
  'Extra fields this business keeps — they appear on the matching form, list and export straight away': {
    hi: 'इस व्यवसाय के अपने अतिरिक्त फ़ील्ड — ये तुरंत संबंधित फ़ॉर्म, सूची और एक्सपोर्ट में दिखने लगते हैं',
    gu: 'આ ધંધો રાખે છે તે વધારાનાં ફીલ્ડ — તે તરત જ સંબંધિત ફૉર્મ, યાદી અને એક્સપોર્ટમાં દેખાય છે',
  },
  'Additional details': { hi: 'अतिरिक्त विवरण', gu: 'વધારાની વિગતો' },
  'New Field': { hi: 'नया फ़ील्ड', gu: 'નવું ફીલ્ડ' },
  'New Custom Field': { hi: 'नया कस्टम फ़ील्ड', gu: 'નવું કસ્ટમ ફીલ્ડ' },
  'Edit Custom Field': { hi: 'कस्टम फ़ील्ड संपादित करें', gu: 'કસ્ટમ ફીલ્ડ સંપાદિત કરો' },
  'Custom field saved': { hi: 'कस्टम फ़ील्ड सहेजा गया', gu: 'કસ્ટમ ફીલ્ડ સાચવ્યું' },
  'Custom field removed': { hi: 'कस्टम फ़ील्ड हटाया गया', gu: 'કસ્ટમ ફીલ્ડ કાઢી નાખ્યું' },
  'Could not save the custom field': {
    hi: 'कस्टम फ़ील्ड सहेजा नहीं जा सका',
    gu: 'કસ્ટમ ફીલ્ડ સાચવી શકાયું નહીં',
  },
  'Remove this field?': { hi: 'यह फ़ील्ड हटाएँ?', gu: 'આ ફીલ્ડ કાઢી નાખવું?' },
  'Values already saved under this key are kept and reappear if you create the field again.': {
    hi: 'इस key के तहत पहले से सहेजे मान बने रहते हैं और फ़ील्ड दोबारा बनाने पर फिर दिखने लगते हैं।',
    gu: 'આ key હેઠળ પહેલેથી સાચવેલા મૂલ્યો રહે છે અને ફીલ્ડ ફરી બનાવો તો પાછા દેખાય છે.',
  },
  'No custom fields yet. Add one to put this trade’s own details on the standard forms.': {
    hi: 'अभी कोई कस्टम फ़ील्ड नहीं। इस धंधे के अपने विवरण मानक फ़ॉर्म पर लाने के लिए एक जोड़ें।',
    gu: 'હજી કોઈ કસ્ટમ ફીલ્ડ નથી. આ ધંધાની પોતાની વિગતો પ્રમાણભૂત ફૉર્મ પર લાવવા એક ઉમેરો.',
  },
  'All records': { hi: 'सभी रिकॉर्ड', gu: 'બધા રેકોર્ડ' },
  'Record': { hi: 'रिकॉर्ड', gu: 'રેકોર્ડ' },
  'What and where': { hi: 'क्या और कहाँ', gu: 'શું અને ક્યાં' },
  'Attach to': { hi: 'किससे जोड़ें', gu: 'કોની સાથે જોડો' },
  'Fixed after creation — values are already stored against it.': {
    hi: 'बनाने के बाद तय — इसके विरुद्ध मान पहले से संग्रहित हैं।',
    gu: 'બનાવ્યા પછી નિશ્ચિત — તેની સામે મૂલ્યો પહેલેથી સંગ્રહિત છે.',
  },
  'Key': { hi: 'Key', gu: 'Key' },
  'Lowercase, no spaces — e.g. isbn_number.': {
    hi: 'छोटे अक्षर, बिना स्पेस — जैसे isbn_number।',
    gu: 'નાના અક્ષર, જગ્યા વગર — દા.ત. isbn_number.',
  },
  'Help text': { hi: 'सहायता पाठ', gu: 'મદદ લખાણ' },
  'Shown under the input on the form.': {
    hi: 'फ़ॉर्म पर इनपुट के नीचे दिखता है।',
    gu: 'ફૉર્મ પર ઇનપુટની નીચે દેખાય છે.',
  },
  'Choices': { hi: 'विकल्प', gu: 'વિકલ્પો' },
  'choices': { hi: 'विकल्प', gu: 'વિકલ્પો' },
  'Add choice': { hi: 'विकल्प जोड़ें', gu: 'વિકલ્પ ઉમેરો' },
  'Stored value': { hi: 'संग्रहित मान', gu: 'સંગ્રહિત મૂલ્ય' },
  'Shown label (optional)': { hi: 'दिखने वाला लेबल (वैकल्पिक)', gu: 'દેખાતું લેબલ (વૈકલ્પિક)' },
  'Rules': { hi: 'नियम', gu: 'નિયમો' },
  // 'Required' is already translated in masters.ts — dictionaries merge into
  // one namespace, so redefining it here would silently retranslate it
  // everywhere else too.
  'The record cannot be saved without it.': {
    hi: 'इसके बिना रिकॉर्ड सहेजा नहीं जा सकता।',
    gu: 'તેના વગર રેકોર્ડ સાચવી શકાતો નથી.',
  },
  'Default value': { hi: 'डिफ़ॉल्ट मान', gu: 'ડિફૉલ્ટ મૂલ્ય' },
  'Pre-filled on new records.': { hi: 'नए रिकॉर्ड पर पहले से भरा होता है।', gu: 'નવા રેકોર્ડ પર પહેલેથી ભરેલું હોય છે.' },
  'Minimum length': { hi: 'न्यूनतम लंबाई', gu: 'ન્યૂનતમ લંબાઈ' },
  'Maximum length': { hi: 'अधिकतम लंबाई', gu: 'મહત્તમ લંબાઈ' },
  'Minimum value': { hi: 'न्यूनतम मान', gu: 'ન્યૂનતમ મૂલ્ય' },
  'Maximum value': { hi: 'अधिकतम मान', gu: 'મહત્તમ મૂલ્ય' },
  'Pattern': { hi: 'पैटर्न', gu: 'પૅટર્ન' },
  'Optional regular expression the whole value must match — worth refusing an ISBN or IMEI at entry.': {
    hi: 'वैकल्पिक regular expression जिससे पूरा मान मेल खाना चाहिए — ISBN या IMEI को प्रविष्टि पर ही रोकने लायक।',
    gu: 'વૈકલ્પિક regular expression જેની સાથે આખું મૂલ્ય મેળ ખાવું જોઈએ — ISBN કે IMEI ને એન્ટ્રી વખતે જ રોકવા યોગ્ય.',
  },
  'Where it shows': { hi: 'कहाँ दिखे', gu: 'ક્યાં દેખાય' },
  'Column on the list screen': { hi: 'सूची स्क्रीन पर कॉलम', gu: 'યાદી સ્ક્રીન પર કૉલમ' },
  'Included in search': { hi: 'खोज में शामिल', gu: 'શોધમાં સામેલ' },
  'Switch off to hide it without losing saved values.': {
    hi: 'सहेजे मान खोए बिना छिपाने के लिए बंद करें।',
    gu: 'સાચવેલા મૂલ્યો ગુમાવ્યા વગર છુપાવવા બંધ કરો.',
  },
  'Only with module': { hi: 'केवल इस मॉड्यूल के साथ', gu: 'ફક્ત આ મોડ્યુલ સાથે' },
  'Always shown': { hi: 'हमेशा दिखे', gu: 'હંમેશાં દેખાય' },
  'Always': { hi: 'हमेशा', gu: 'હંમેશાં' },
  'Hidden when that module is switched off.': {
    hi: 'वह मॉड्यूल बंद होने पर छिपा रहता है।',
    gu: 'તે મોડ્યુલ બંધ હોય ત્યારે છુપાયેલું રહે છે.',
  },
  'Sort order': { hi: 'क्रम', gu: 'ક્રમ' },
  'Shown in': { hi: 'कहाँ दिखता है', gu: 'ક્યાં દેખાય છે' },
  'In list': { hi: 'सूची में', gu: 'યાદીમાં' },
  'In search': { hi: 'खोज में', gu: 'શોધમાં' },
  'Form only': { hi: 'केवल फ़ॉर्म', gu: 'ફક્ત ફૉર્મ' },
  'Off': { hi: 'बंद', gu: 'બંધ' },
  // Field type names, as the builder lists them. 'Text' and 'Remove' are
  // already owned by masters.ts / sales.ts and are left to them.
  'Long text': { hi: 'लंबा टेक्स्ट', gu: 'લાંબું ટેક્સ્ટ' },
  'Whole number': { hi: 'पूर्ण संख्या', gu: 'પૂર્ણ સંખ્યા' },
  'Decimal': { hi: 'दशमलव', gu: 'દશાંશ' },
  'Dropdown (one)': { hi: 'ड्रॉपडाउन (एक)', gu: 'ડ્રૉપડાઉન (એક)' },
  'Dropdown (many)': { hi: 'ड्रॉपडाउन (कई)', gu: 'ડ્રૉપડાઉન (ઘણા)' },
  'Yes / No': { hi: 'हाँ / नहीं', gu: 'હા / ના' },
  'Link': { hi: 'लिंक', gu: 'લિંક' },
}

export default phrases
