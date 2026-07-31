// Device Manager screens: DeviceManagerView (configuration, test connection,
// simulator, live event log) and DeviceEventsView (searchable history).
import type { PhraseFile } from './types'

const phrases: PhraseFile = {
  // --- Screen chrome ---
  'Device Manager': { hi: 'डिवाइस मैनेजर', gu: 'ડિવાઇસ મેનેજર' },
  'devices': { hi: 'डिवाइस', gu: 'ડિવાઇસ' },
  'Each device runs through a brand adapter. Keep a device in Simulator mode to build and test a flow — mock scans, fake weights, virtual fingerprint events and print previews — with no hardware attached.': {
    hi: 'हर डिवाइस एक ब्रांड अडैप्टर से चलता है। डिवाइस को सिमुलेटर मोड में रखें और बिना हार्डवेयर — मॉक स्कैन, नकली वज़न, वर्चुअल फ़िंगरप्रिंट इवेंट और प्रिंट प्रीव्यू से — पूरा फ़्लो बनाकर टेस्ट करें।',
    gu: 'દરેક ડિવાઇસ એક બ્રાન્ડ અડેપ્ટર દ્વારા ચાલે છે. ડિવાઇસને સિમ્યુલેટર મોડમાં રાખો અને હાર્ડવેર વગર — મોક સ્કૅન, ખોટું વજન, વર્ચ્યુઅલ ફિંગરપ્રિન્ટ ઇવેન્ટ અને પ્રિન્ટ પ્રીવ્યૂ સાથે — આખો ફ્લો બનાવીને ટેસ્ટ કરો.',
  },
  'Add a device': { hi: 'डिवाइस जोड़ें', gu: 'ડિવાઇસ ઉમેરો' },
  'No devices configured yet. Pick a brand below — it starts in Simulator mode, so you can use it immediately.': {
    hi: 'अभी कोई डिवाइस कॉन्फ़िगर नहीं है। नीचे से ब्रांड चुनें — यह सिमुलेटर मोड में शुरू होता है, इसलिए तुरंत उपयोग कर सकते हैं।',
    gu: 'હજી કોઈ ડિવાઇસ કૉન્ફિગર થયું નથી. નીચેથી બ્રાન્ડ પસંદ કરો — તે સિમ્યુલેટર મોડમાં શરૂ થાય છે, તેથી તરત વાપરી શકાય.',
  },
  'All': { hi: 'सभी', gu: 'બધા' },

  // --- Device classes (from the backend registry) ---
  'Barcode / QR Scanner': { hi: 'बारकोड / QR स्कैनर', gu: 'બારકોડ / QR સ્કૅનર' },
  'Printer': { hi: 'प्रिंटर', gu: 'પ્રિન્ટર' },
  'Weighing Scale': { hi: 'वज़न काँटा', gu: 'વજન કાંટો' },
  'RFID Reader': { hi: 'RFID रीडर', gu: 'RFID રીડર' },
  'Biometric Terminal': { hi: 'बायोमेट्रिक टर्मिनल', gu: 'બાયોમેટ્રિક ટર્મિનલ' },
  'Counter and warehouse scanners feeding POS, GRN, stock and gate screens.': {
    hi: 'POS, GRN, स्टॉक और गेट स्क्रीन को फीड करने वाले काउंटर व वेयरहाउस स्कैनर।',
    gu: 'POS, GRN, સ્ટોક અને ગેટ સ્ક્રીનને ફીડ કરતા કાઉન્ટર અને વેરહાઉસ સ્કૅનર.',
  },
  'Thermal receipt printers and barcode/price-tag label printers.': {
    hi: 'थर्मल रसीद प्रिंटर और बारकोड/प्राइस-टैग लेबल प्रिंटर।',
    gu: 'થર્મલ રસીદ પ્રિન્ટર અને બારકોડ/પ્રાઇસ-ટૅગ લેબલ પ્રિન્ટર.',
  },
  'Counter scales and weighbridges streaming a live weight frame.': {
    hi: 'लाइव वज़न फ़्रेम भेजने वाले काउंटर काँटे और धर्मकांटे।',
    gu: 'લાઇવ વજન ફ્રેમ મોકલતા કાઉન્ટર કાંટા અને ધર્મકાંટા.',
  },
  'UHF readers for gate passes, carton verification and bulk stock counts.': {
    hi: 'गेट पास, कार्टन जाँच और थोक स्टॉक गिनती के लिए UHF रीडर।',
    gu: 'ગેટ પાસ, કાર્ટન ચકાસણી અને જથ્થાબંધ સ્ટોક ગણતરી માટે UHF રીડર.',
  },
  'Fingerprint and face attendance terminals feeding HR attendance.': {
    hi: 'HR उपस्थिति को फीड करने वाले फ़िंगरप्रिंट और फ़ेस अटेंडेंस टर्मिनल।',
    gu: 'HR હાજરીને ફીડ કરતા ફિંગરપ્રિન્ટ અને ફેસ અટેન્ડન્સ ટર્મિનલ.',
  },

  // --- Card ---
  'Default': { hi: 'डिफ़ॉल्ट', gu: 'ડિફૉલ્ટ' },
  'Live': { hi: 'लाइव', gu: 'લાઇવ' },
  'Simulator': { hi: 'सिमुलेटर', gu: 'સિમ્યુલેટર' },
  'online': { hi: 'ऑनलाइन', gu: 'ઓનલાઇન' },
  'offline': { hi: 'ऑफ़लाइन', gu: 'ઓફલાઇન' },
  'error': { hi: 'त्रुटि', gu: 'ભૂલ' },
  'unknown': { hi: 'अज्ञात', gu: 'અજાણ' },
  'Models': { hi: 'मॉडल', gu: 'મોડેલ' },
  'HR device': { hi: 'HR डिवाइस', gu: 'HR ડિવાઇસ' },
  'Last tested': { hi: 'अंतिम जाँच', gu: 'છેલ્લી ચકાસણી' },
  'Events': { hi: 'इवेंट', gu: 'ઇવેન્ટ' },
  'Live hardware': { hi: 'लाइव हार्डवेयर', gu: 'લાઇવ હાર્ડવેર' },
  'Simulator mode': { hi: 'सिमुलेटर मोड', gu: 'સિમ્યુલેટર મોડ' },
  'Test Connection': { hi: 'कनेक्शन जाँचें', gu: 'કનેક્શન તપાસો' },
  'Connection OK': { hi: 'कनेक्शन ठीक है', gu: 'કનેક્શન બરાબર' },
  'Connection failed': { hi: 'कनेक्शन विफल', gu: 'કનેક્શન નિષ્ફળ' },
  'Switched to Live': { hi: 'लाइव मोड पर स्विच किया', gu: 'લાઇવ મોડ પર સ્વિચ કર્યું' },
  'Switched to Simulator': { hi: 'सिमुलेटर मोड पर स्विच किया', gu: 'સિમ્યુલેટર મોડ પર સ્વિચ કર્યું' },
  'Could not switch mode': { hi: 'मोड बदल नहीं सका', gu: 'મોડ બદલી શકાયો નથી' },

  // --- Simulator actions (registry labels) ---
  'Mock Barcode Scan': { hi: 'मॉक बारकोड स्कैन', gu: 'મોક બારકોડ સ્કૅન' },
  'Print Preview': { hi: 'प्रिंट प्रीव्यू', gu: 'પ્રિન્ટ પ્રીવ્યૂ' },
  'Fake Weight Reading': { hi: 'नकली वज़न रीडिंग', gu: 'ખોટું વજન રીડિંગ' },
  'Virtual Tag Read': { hi: 'वर्चुअल टैग रीड', gu: 'વર્ચ્યુઅલ ટૅગ રીડ' },
  'Virtual Fingerprint Event': { hi: 'वर्चुअल फ़िंगरप्रिंट इवेंट', gu: 'વર્ચ્યુઅલ ફિંગરપ્રિન્ટ ઇવેન્ટ' },

  // --- Device actions ---
  'Decode': { hi: 'डिकोड', gu: 'ડિકોડ' },
  'Poll scan': { hi: 'स्कैन लें', gu: 'સ્કૅન લો' },
  'Test print': { hi: 'टेस्ट प्रिंट', gu: 'ટેસ્ટ પ્રિન્ટ' },
  'Open drawer': { hi: 'ड्रॉअर खोलें', gu: 'ડ્રોઅર ખોલો' },
  'Read weight': { hi: 'वज़न पढ़ें', gu: 'વજન વાંચો' },
  'Tare': { hi: 'टेयर', gu: 'ટેર' },
  'Read tags': { hi: 'टैग पढ़ें', gu: 'ટૅગ વાંચો' },
  'Pull punches': { hi: 'पंच लाएँ', gu: 'પંચ લાવો' },
  '{n} tag(s) read': { hi: '{n} टैग पढ़े गए', gu: '{n} ટૅગ વંચાયા' },
  '{n} punch(es) stored': { hi: '{n} पंच सहेजे गए', gu: '{n} પંચ સાચવ્યાં' },
  'Sent to printer': { hi: 'प्रिंटर को भेजा', gu: 'પ્રિન્ટરને મોકલ્યું' },
  'Completed': { hi: 'पूर्ण', gu: 'પૂર્ણ' },

  // --- Configuration dialog ---
  'Add {brand}': { hi: '{brand} जोड़ें', gu: '{brand} ઉમેરો' },
  'Edit device': { hi: 'डिवाइस संपादित करें', gu: 'ડિવાઇસ સંપાદિત કરો' },
  'Device saved': { hi: 'डिवाइस सहेजा गया', gu: 'ડિવાઇસ સાચવ્યું' },
  'Check the highlighted fields': { hi: 'चिह्नित फ़ील्ड जाँचें', gu: 'ચિહ્નિત ફીલ્ડ તપાસો' },
  'Counter 1, packing table, gate…': { hi: 'काउंटर 1, पैकिंग टेबल, गेट…', gu: 'કાઉન્ટર 1, પૅકિંગ ટેબલ, ગેટ…' },
  'Mode': { hi: 'मोड', gu: 'મોડ' },
  'No hardware needed — the adapter answers from its built-in fake.': {
    hi: 'हार्डवेयर की ज़रूरत नहीं — अडैप्टर अपने अंतर्निहित नकली डेटा से जवाब देता है।',
    gu: 'હાર્ડવેરની જરૂર નથી — અડેપ્ટર તેના બિલ્ટ-ઇન નકલી ડેટા વડે જવાબ આપે છે.',
  },
  'Required fields must be filled before Live mode saves.': {
    hi: 'लाइव मोड सहेजने से पहले आवश्यक फ़ील्ड भरनी होंगी।',
    gu: 'લાઇવ મોડ સાચવતાં પહેલાં જરૂરી ફીલ્ડ ભરવાં પડશે.',
  },
  'Not linked': { hi: 'लिंक नहीं है', gu: 'લિંક નથી' },
  'HR biometric device': { hi: 'HR बायोमेट्रिक डिवाइस', gu: 'HR બાયોમેટ્રિક ડિવાઇસ' },
  'Punches pulled here are stored against this HR device, so attendance picks them up.': {
    hi: 'यहाँ लाए गए पंच इसी HR डिवाइस के नाम सहेजे जाते हैं, जिससे उपस्थिति उन्हें उठा लेती है।',
    gu: 'અહીં લાવેલા પંચ આ જ HR ડિવાઇસ સામે સાચવાય છે, જેથી હાજરી તેમને લઈ લે.',
  },
  'Default for this type': { hi: 'इस प्रकार के लिए डिफ़ॉल्ट', gu: 'આ પ્રકાર માટે ડિફૉલ્ટ' },
  'Connection & options': { hi: 'कनेक्शन और विकल्प', gu: 'કનેક્શન અને વિકલ્પો' },
  'Credentials': { hi: 'क्रेडेंशियल', gu: 'ક્રેડેન્શિયલ' },
  'saved, leave blank to keep': { hi: 'सहेजा गया — बदलना न हो तो खाली छोड़ें', gu: 'સાચવેલું — બદલવું ન હોય તો ખાલી રાખો' },
  'Remove {name}?': { hi: '{name} हटाएँ?', gu: '{name} દૂર કરવું?' },
  'Its configuration, saved credentials and event history are deleted.': {
    hi: 'इसका कॉन्फ़िगरेशन, सहेजे गए क्रेडेंशियल और इवेंट इतिहास मिट जाएगा।',
    gu: 'તેનું કૉન્ફિગરેશન, સાચવેલા ક્રેડેન્શિયલ અને ઇવેન્ટ ઇતિહાસ ભૂંસાઈ જશે.',
  },

  // --- Simulator dialog ---
  'Runs the same adapter code the hardware would, so what you see here is what the device will produce.': {
    hi: 'यह वही अडैप्टर कोड चलाता है जो हार्डवेयर चलाता है, इसलिए जो यहाँ दिख रहा है वही डिवाइस बनाएगा।',
    gu: 'આ એ જ અડેપ્ટર કોડ ચલાવે છે જે હાર્ડવેર ચલાવે છે, તેથી અહીં દેખાય તે જ ડિવાઇસ બનાવશે.',
  },
  'Barcode to scan': { hi: 'स्कैन करने का बारकोड', gu: 'સ્કૅન કરવાનો બારકોડ' },
  'Leave blank for a random sample code': { hi: 'खाली छोड़ें तो कोई नमूना कोड लिया जाएगा', gu: 'ખાલી રાખો તો કોઈ નમૂના કોડ લેવાશે' },
  'Document': { hi: 'दस्तावेज़', gu: 'દસ્તાવેજ' },
  'Copies': { hi: 'प्रतियाँ', gu: 'નકલો' },
  'Weight': { hi: 'वज़न', gu: 'વજન' },
  'Random': { hi: 'यादृच्छिक', gu: 'રેન્ડમ' },
  'Stable reading': { hi: 'स्थिर रीडिंग', gu: 'સ્થિર રીડિંગ' },
  'Turn off to check that your screen refuses an unsettled weight.': {
    hi: 'बंद करके जाँचें कि आपकी स्क्रीन अस्थिर वज़न को अस्वीकार करती है।',
    gu: 'બંધ કરીને તપાસો કે તમારી સ્ક્રીન અસ્થિર વજન નકારે છે.',
  },
  'EPCs to read': { hi: 'पढ़ने के लिए EPC', gu: 'વાંચવા માટે EPC' },
  'Comma or newline separated. Leave blank to generate random tags.': {
    hi: 'कॉमा या नई पंक्ति से अलग करें। खाली छोड़ें तो यादृच्छिक टैग बनेंगे।',
    gu: 'કૉમા અથવા નવી લીટીથી અલગ કરો. ખાલી રાખો તો રેન્ડમ ટૅગ બનશે.',
  },
  'Tag count (when blank)': { hi: 'टैग संख्या (खाली होने पर)', gu: 'ટૅગ સંખ્યા (ખાલી હોય ત્યારે)' },
  'Device user IDs': { hi: 'डिवाइस यूज़र ID', gu: 'ડિવાઇસ યુઝર ID' },
  "The employee's biometric user id. Blank generates test ids.": {
    hi: 'कर्मचारी का बायोमेट्रिक यूज़र ID। खाली छोड़ने पर टेस्ट ID बनेंगे।',
    gu: 'કર્મચારીનો બાયોમેટ્રિક યુઝર ID. ખાલી રાખો તો ટેસ્ટ ID બનશે.',
  },
  'Punch count (when blank)': { hi: 'पंच संख्या (खाली होने पर)', gu: 'પંચ સંખ્યા (ખાલી હોય ત્યારે)' },
  'Store the punches (feeds HR attendance)': {
    hi: 'पंच सहेजें (HR उपस्थिति में जाएँगे)',
    gu: 'પંચ સાચવો (HR હાજરીમાં જશે)',
  },
  'Result': { hi: 'परिणाम', gu: 'પરિણામ' },
  'Simulation failed': { hi: 'सिमुलेशन विफल', gu: 'સિમ્યુલેશન નિષ્ફળ' },
  'Run': { hi: 'चलाएँ', gu: 'ચલાવો' },
  'bytes': { hi: 'बाइट', gu: 'બાઇટ' },
  'copy(ies)': { hi: 'प्रति', gu: 'નકલ' },
  'In': { hi: 'इन', gu: 'ઇન' },
  'Out': { hi: 'आउट', gu: 'આઉટ' },

  // --- Live event log ---
  'Live Event Log': { hi: 'लाइव इवेंट लॉग', gu: 'લાઇવ ઇવેન્ટ લોગ' },
  'Tailing': { hi: 'लाइव चल रहा है', gu: 'લાઇવ ચાલુ છે' },
  'Paused': { hi: 'रुका हुआ', gu: 'થોભેલું' },
  'All devices': { hi: 'सभी डिवाइस', gu: 'બધા ડિવાઇસ' },
  'Clear log': { hi: 'लॉग साफ़ करें', gu: 'લોગ સાફ કરો' },
  'Nothing yet. Test a connection or run a simulation and it will appear here.': {
    hi: 'अभी कुछ नहीं। कनेक्शन जाँचें या सिमुलेशन चलाएँ — यहाँ दिखने लगेगा।',
    gu: 'હજી કંઈ નથી. કનેક્શન તપાસો અથવા સિમ્યુલેશન ચલાવો — અહીં દેખાવા લાગશે.',
  },
  'Clear the event log?': { hi: 'इवेंट लॉग साफ़ करें?', gu: 'ઇવેન્ટ લોગ સાફ કરવો?' },
  'Events for the selected device are deleted.': {
    hi: 'चुने गए डिवाइस के इवेंट मिट जाएँगे।',
    gu: 'પસંદ કરેલા ડિવાઇસના ઇવેન્ટ ભૂંસાઈ જશે.',
  },
  'All recorded device events are deleted.': {
    hi: 'सभी दर्ज डिवाइस इवेंट मिट जाएँगे।',
    gu: 'બધા નોંધાયેલા ડિવાઇસ ઇવેન્ટ ભૂંસાઈ જશે.',
  },

  // --- Event log screen ---
  'Device Event Log': { hi: 'डिवाइस इवेंट लॉग', gu: 'ડિવાઇસ ઇવેન્ટ લોગ' },
  'Device Events': { hi: 'डिवाइस इवेंट', gu: 'ડિવાઇસ ઇવેન્ટ' },
  'events': { hi: 'इवेंट', gu: 'ઇવેન્ટ' },
  'All events': { hi: 'सभी इवेंट', gu: 'બધા ઇવેન્ટ' },
  'Any status': { hi: 'कोई भी स्थिति', gu: 'કોઈપણ સ્થિતિ' },
  'Live & simulated': { hi: 'लाइव और सिमुलेटेड', gu: 'લાઇવ અને સિમ્યુલેટેડ' },
  'No events match these filters.': { hi: 'इन फ़िल्टर से कोई इवेंट मेल नहीं खाता।', gu: 'આ ફિલ્ટર સાથે કોઈ ઇવેન્ટ મેળ ખાતું નથી.' },
  'Adapter': { hi: 'अडैप्टर', gu: 'અડેપ્ટર' },
  'Event': { hi: 'इवेंट', gu: 'ઇવેન્ટ' },
  'Source': { hi: 'स्रोत', gu: 'સ્રોત' },
  'ms': { hi: 'मि.से.', gu: 'મિ.સે.' },
  'View payload': { hi: 'पेलोड देखें', gu: 'પેલોડ જુઓ' },
  'Event payload': { hi: 'इवेंट पेलोड', gu: 'ઇવેન્ટ પેલોડ' },
  'No payload recorded.': { hi: 'कोई पेलोड दर्ज नहीं।', gu: 'કોઈ પેલોડ નોંધાયું નથી.' },
  // Event-type values, lower-cased as stored.
  'connection': { hi: 'कनेक्शन', gu: 'કનેક્શન' },
  'scan': { hi: 'स्कैन', gu: 'સ્કૅન' },
  'print': { hi: 'प्रिंट', gu: 'પ્રિન્ટ' },
  'weight': { hi: 'वज़न', gu: 'વજન' },
  'tag': { hi: 'टैग', gu: 'ટૅગ' },
  'punch': { hi: 'पंच', gu: 'પંચ' },
  'success': { hi: 'सफल', gu: 'સફળ' },
  'failed': { hi: 'विफल', gu: 'નિષ્ફળ' },
  'info': { hi: 'सूचना', gu: 'માહિતી' },
}

export default phrases
