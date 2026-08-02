// Sports & Fitness plugin phrases: the hire desk, the fleet behind it and the
// utilisation report that decides what to buy next season.
import type { PhraseFile } from './types'

const phrases: PhraseFile = {
  // ---- The desk ----
  'Hire Desk': { hi: 'किराया डेस्क', gu: 'ભાડા ડેસ્ક' },
  'What is out, what is late, and what is back': {
    hi: 'क्या बाहर है, क्या देर से है, और क्या वापस आया',
    gu: 'શું બહાર છે, શું મોડું છે, અને શું પાછું આવ્યું',
  },
  'New Hire': { hi: 'नया किराया', gu: 'નવું ભાડું' },
  'Edit Booking': { hi: 'बुकिंग संपादित करें', gu: 'બુકિંગ ફેરફાર કરો' },
  'Hire #': { hi: 'किराया क्र.', gu: 'ભાડા ક્ર.' },
  'Hire Contract': { hi: 'किराया अनुबंध', gu: 'ભાડા કરાર' },
  'Kit': { hi: 'सामान', gu: 'સામાન' },
  'Due back': { hi: 'वापसी कब', gu: 'પરત ક્યારે' },
  'due back today': { hi: 'आज वापस आना है', gu: 'આજે પરત આવવાનું' },
  'Out': { hi: 'बाहर', gu: 'બહાર' },
  'Out from': { hi: 'कब से बाहर', gu: 'ક્યારથી બહાર' },
  'Booked': { hi: 'बुक', gu: 'બુક' },
  'Booked ahead': { hi: 'आगे की बुकिंग', gu: 'આગળની બુકિંગ' },
  'Overdue': { hi: 'समय बीत गया', gu: 'સમય વીતી ગયો' },
  'Overdue only': { hi: 'केवल विलंबित', gu: 'ફક્ત મોડા' },
  'Include settled': { hi: 'निपटाए हुए भी दिखाएँ', gu: 'પતાવેલા પણ બતાવો' },
  'Back, not settled': { hi: 'वापस, हिसाब बाकी', gu: 'પાછું, હિસાબ બાકી' },
  'Settled': { hi: 'हिसाब हो गया', gu: 'હિસાબ થઈ ગયો' },
  'Still out': { hi: 'अभी बाहर', gu: 'હજુ બહાર' },
  'still out': { hi: 'अभी बाहर', gu: 'હજુ બહાર' },
  'Deposits held': { hi: 'जमा राशि हमारे पास', gu: 'થાપણ આપણી પાસે' },
  'the customers’ money': { hi: 'यह ग्राहकों का पैसा है', gu: 'આ ગ્રાહકોના પૈસા છે' },
  'Search hire no., customer or asset code': {
    hi: 'किराया क्रमांक, ग्राहक या सामान कोड खोजें',
    gu: 'ભાડા ક્રમાંક, ગ્રાહક કે સામાન કોડ શોધો',
  },

  // ---- Booking a hire ----
  'Who is hiring?': { hi: 'किराए पर कौन ले रहा है?', gu: 'ભાડે કોણ લે છે?' },
  'Charged': { hi: 'शुल्क आधार', gu: 'ભાડા આધાર' },
  'Per hour': { hi: 'प्रति घंटा', gu: 'પ્રતિ કલાક' },
  'Per day': { hi: 'प्रति दिन', gu: 'પ્રતિ દિવસ' },
  'Per week': { hi: 'प्रति सप्ताह', gu: 'પ્રતિ અઠવાડિયું' },
  'Per month': { hi: 'प्रति माह', gu: 'પ્રતિ મહિનો' },
  'hourly': { hi: 'प्रति घंटा', gu: 'પ્રતિ કલાક' },
  'daily': { hi: 'प्रति दिन', gu: 'પ્રતિ દિવસ' },
  'weekly': { hi: 'प्रति सप्ताह', gu: 'પ્રતિ અઠવાડિયું' },
  'monthly': { hi: 'प्रति माह', gu: 'પ્રતિ મહિનો' },
  'Part of a period counts as a whole one — six hours of a daily hire is a day.': {
    hi: 'अवधि का कुछ हिस्सा भी पूरी अवधि गिना जाता है — दैनिक किराए के छह घंटे यानी एक दिन।',
    gu: 'સમયગાળાનો થોડો ભાગ પણ આખો ગણાય — રોજિંદા ભાડાના છ કલાક એટલે એક દિવસ.',
  },
  'Grace (hours)': { hi: 'छूट (घंटे)', gu: 'છૂટ (કલાક)' },
  'How late is late. Settles the twenty-minute overrun before it is argued about.': {
    hi: 'कितनी देरी को देरी मानें। बीस मिनट की देरी पर होने वाली बहस पहले ही तय कर देता है।',
    gu: 'કેટલું મોડું એટલે મોડું. વીસ મિનિટના વિલંબ પરની દલીલ પહેલેથી જ પતાવી દે છે.',
  },
  'ID type': { hi: 'पहचान का प्रकार', gu: 'ઓળખનો પ્રકાર' },
  'ID number': { hi: 'पहचान क्रमांक', gu: 'ઓળખ ક્રમાંક' },
  'ID seen': { hi: 'पहचान देखी', gu: 'ઓળખ જોઈ' },
  'ID needed': { hi: 'पहचान आवश्यक', gu: 'ઓળખ જરૂરી' },
  'Aadhaar, driving licence…': { hi: 'आधार, ड्राइविंग लाइसेंस…', gu: 'આધાર, ડ્રાઇવિંગ લાયસન્સ…' },
  'Some of this kit is not handed over without identification. Record it now — the handover will refuse without it.': {
    hi: 'यह सामान बिना पहचान देखे नहीं दिया जाता। अभी दर्ज करें — बिना इसके सुपुर्दगी नहीं होगी।',
    gu: 'આ સામાન ઓળખ જોયા વગર અપાતો નથી. હમણાં નોંધો — તેના વગર સોંપણી થશે નહીં.',
  },
  'Chosen': { hi: 'चुना गया', gu: 'પસંદ કરેલું' },
  'Per period': { hi: 'प्रति अवधि', gu: 'પ્રતિ સમયગાળો' },
  'Deposit to take': { hi: 'जमा लेनी है', gu: 'થાપણ લેવાની છે' },
  'Deposit': { hi: 'जमा', gu: 'થાપણ' },
  'Deposit held': { hi: 'जमा हमारे पास', gu: 'થાપણ આપણી પાસે' },
  'Nothing in the fleet is free over that period on this basis. Try another window, or check the fleet register.': {
    hi: 'उस अवधि में इस आधार पर कोई सामान खाली नहीं है। दूसरी अवधि देखें, या सामान रजिस्टर जाँचें।',
    gu: 'તે સમયગાળામાં આ આધારે કોઈ સામાન ખાલી નથી. બીજો સમય જુઓ, અથવા સામાન રજિસ્ટર તપાસો.',
  },
  'Nothing on hire. A new hire holds the kit from the moment it is booked, so nobody else can be promised it.': {
    hi: 'कोई किराया नहीं। बुकिंग होते ही सामान रोक लिया जाता है, ताकि वही सामान किसी और को न दिया जाए।',
    gu: 'કોઈ ભાડું નથી. બુકિંગ થતાં જ સામાન રોકાઈ જાય, જેથી એ જ સામાન બીજા કોઈને ન અપાય.',
  },
  'Booking updated': { hi: 'बुकिंग अद्यतन', gu: 'બુકિંગ અપડેટ' },
  'Could not save the booking': { hi: 'बुकिंग सहेजी नहीं जा सकी', gu: 'બુકિંગ સાચવી શકાઈ નહીં' },

  // ---- Working the board ----
  'Hand over': { hi: 'सुपुर्द करें', gu: 'સોંપો' },
  'Take back': { hi: 'वापस लें', gu: 'પાછું લો' },
  'Take Kit Back': { hi: 'सामान वापस लें', gu: 'સામાન પાછો લો' },
  'Extend': { hi: 'अवधि बढ़ाएँ', gu: 'સમય વધારો' },
  'Extend to when?': { hi: 'कब तक बढ़ाएँ?', gu: 'ક્યાં સુધી વધારવું?' },
  'Contract': { hi: 'अनुबंध', gu: 'કરાર' },
  'Cancel this booking?': { hi: 'यह बुकिंग रद्द करें?', gu: 'આ બુકિંગ રદ કરવી?' },
  'The kit goes back into availability straight away.': {
    hi: 'सामान तुरंत फिर से उपलब्ध हो जाएगा।',
    gu: 'સામાન તરત જ ફરી ઉપલબ્ધ થઈ જશે.',
  },
  'Cancel Booking': { hi: 'बुकिंग रद्द करें', gu: 'બુકિંગ રદ કરો' },
  'Keep It': { hi: 'रहने दें', gu: 'રહેવા દો' },
  'Delete this hire?': { hi: 'यह किराया हटाएँ?', gu: 'આ ભાડું કાઢી નાખવું?' },
  'Only a hire that never went out can be deleted.': {
    hi: 'केवल वही किराया हटाया जा सकता है जो कभी बाहर गया ही नहीं।',
    gu: 'ફક્ત તે ભાડું કાઢી શકાય જે ક્યારેય બહાર ગયું જ નથી.',
  },
  'That did not work': { hi: 'यह नहीं हो सका', gu: 'આ થઈ શક્યું નહીં' },
  'h late': { hi: 'घंटे देर', gu: 'કલાક મોડું' },
  'd late': { hi: 'दिन देर', gu: 'દિવસ મોડું' },

  // ---- Return ----
  'Back at': { hi: 'वापस आया', gu: 'પાછું આવ્યું' },
  'Back?': { hi: 'वापस?', gu: 'પાછું?' },
  'Back as': { hi: 'किस हालत में वापस', gu: 'કઈ સ્થિતિમાં પાછું' },
  'Out as': { hi: 'किस हालत में गया', gu: 'કઈ સ્થિતિમાં ગયું' },
  'Damage': { hi: 'नुकसान', gu: 'નુકસાન' },
  'What happened': { hi: 'क्या हुआ', gu: 'શું થયું' },
  'To bench': { hi: 'मरम्मत में', gu: 'સમારકામમાં' },
  'Required': { hi: 'आवश्यक', gu: 'જરૂરી' },
  'Charged on this return': { hi: 'इस वापसी पर शुल्क', gu: 'આ પરત પર ચાર્જ' },
  'Take back what is actually here. Anything left unticked stays out, keeps running, and stays unavailable to the next customer.': {
    hi: 'जो सचमुच आया है वही वापस लें। बिना निशान छोड़ा सामान बाहर ही रहेगा, उसका शुल्क चलता रहेगा और अगले ग्राहक को नहीं मिलेगा।',
    gu: 'જે ખરેખર આવ્યું છે એ જ પાછું લો. નિશાન વગર છોડેલો સામાન બહાર જ રહેશે, તેનું ભાડું ચાલુ રહેશે અને આગલા ગ્રાહકને નહીં મળે.',
  },
  'Written up on Monday for a Sunday return? Say so — the late fee is worked out from this.': {
    hi: 'रविवार की वापसी सोमवार को दर्ज कर रहे हैं? यही समय लिखें — विलंब शुल्क इसी से गिना जाता है।',
    gu: 'રવિવારની પરત સોમવારે નોંધો છો? એ જ સમય લખો — વિલંબ ચાર્જ આના પરથી ગણાય છે.',
  },
  'Could not record the return': { hi: 'वापसी दर्ज नहीं हो सकी', gu: 'પરત નોંધી શકાઈ નહીં' },

  // ---- Settlement ----
  'Settle': { hi: 'हिसाब करें', gu: 'હિસાબ કરો' },
  'Settle Hire': { hi: 'किराए का हिसाब', gu: 'ભાડાનો હિસાબ' },
  'Settle & Bill': { hi: 'हिसाब कर बिल बनाएँ', gu: 'હિસાબ કરી બિલ બનાવો' },
  'Hire': { hi: 'किराया', gu: 'ભાડું' },
  'Late fee': { hi: 'विलंब शुल्क', gu: 'વિલંબ ચાર્જ' },
  'Late': { hi: 'विलंब', gu: 'વિલંબ' },
  'Bill': { hi: 'बिल', gu: 'બિલ' },
  'Refund to customer': { hi: 'ग्राहक को वापस', gu: 'ગ્રાહકને પરત' },
  'Still to collect': { hi: 'अभी लेना है', gu: 'હજુ લેવાનું' },
  'To collect': { hi: 'लेना है', gu: 'લેવાનું' },
  'To refund': { hi: 'लौटाना है', gu: 'પરત આપવાનું' },
  'If it came back now': { hi: 'अभी वापस आए तो', gu: 'હમણાં પાછું આવે તો' },
  'Charges': { hi: 'शुल्क', gu: 'ચાર્જ' },
  'How much of it to keep against the bill.': {
    hi: 'बिल के बदले इसमें से कितना रखना है।',
    gu: 'બિલ સામે આમાંથી કેટલું રાખવાનું છે.',
  },
  'The deposit is the customer’s money until you keep some of it. Whatever is applied here settles the bill; the rest goes back.': {
    hi: 'जमा राशि तब तक ग्राहक की है जब तक आप उसमें से कुछ रख न लें। यहाँ जितना लगाया जाएगा उतना बिल में जाएगा; बाकी वापस।',
    gu: 'થાપણ ત્યાં સુધી ગ્રાહકની છે જ્યાં સુધી તમે તેમાંથી કંઈ રાખો નહીં. અહીં જેટલું લગાડશો એટલું બિલમાં જશે; બાકીનું પરત.',
  },
  'Could not settle the hire': { hi: 'किराए का हिसाब नहीं हो सका', gu: 'ભાડાનો હિસાબ થઈ શક્યો નહીં' },

  // ---- The fleet ----
  'Hire Fleet': { hi: 'किराया सामान', gu: 'ભાડા સામાન' },
  'What the shop owns, what it costs to hire, and where it is': {
    hi: 'दुकान के पास क्या है, किराया कितना है, और वह कहाँ है',
    gu: 'દુકાન પાસે શું છે, ભાડું કેટલું છે, અને એ ક્યાં છે',
  },
  'Add Kit': { hi: 'सामान जोड़ें', gu: 'સામાન ઉમેરો' },
  'Edit Kit': { hi: 'सामान संपादित करें', gu: 'સામાન ફેરફાર કરો' },
  'Free now': { hi: 'अभी खाली', gu: 'હમણાં ખાલી' },
  'Held on bookings': { hi: 'बुकिंग में रुका', gu: 'બુકિંગમાં રોકાયેલું' },
  'On the rack': { hi: 'दुकान में', gu: 'દુકાનમાં' },
  'On the bench': { hi: 'मरम्मत में', gu: 'સમારકામમાં' },
  'Written off': { hi: 'बट्टे खाते', gu: 'માંડવાળ' },
  'Send to the bench': { hi: 'मरम्मत में भेजें', gu: 'સમારકામમાં મોકલો' },
  'Back on the rack': { hi: 'वापस दुकान में', gu: 'પાછું દુકાનમાં' },
  'Remove from the fleet?': { hi: 'सामान सूची से हटाएँ?', gu: 'સામાન યાદીમાંથી કાઢવું?' },
  'Kit that has been out on hire is retired instead — deleting it would take its earnings and its history with it.': {
    hi: 'जो सामान कभी किराए पर गया है उसे हटाया नहीं, बट्टे खाते डाला जाता है — हटाने से उसकी कमाई और इतिहास भी चला जाएगा।',
    gu: 'જે સામાન ક્યારેય ભાડે ગયો હોય તેને કાઢાતો નથી, માંડવાળ કરાય છે — કાઢવાથી તેની કમાણી અને ઇતિહાસ પણ જતો રહેશે.',
  },
  'Asset code': { hi: 'सामान कोड', gu: 'સામાન કોડ' },
  'What is painted on it, or on the tag tied to it.': {
    hi: 'जो उस पर लिखा है, या उससे बँधे टैग पर।',
    gu: 'જે તેના પર લખ્યું છે, અથવા તેની સાથે બાંધેલા ટૅગ પર.',
  },
  'Serial / frame no.': { hi: 'सीरियल / फ्रेम क्र.', gu: 'સીરીયલ / ફ્રેમ ક્ર.' },
  'Search code, name or serial': { hi: 'कोड, नाम या सीरियल खोजें', gu: 'કોડ, નામ કે સીરીયલ શોધો' },
  'Cycles, Racquets, Camping…': { hi: 'साइकिल, रैकेट, कैंपिंग…', gu: 'સાયકલ, રેકેટ, કેમ્પિંગ…' },
  'Rates': { hi: 'दरें', gu: 'દરો' },
  'Leave a basis empty if the kit is not hired that way — nobody hires a treadmill by the hour, and an empty rate stops it being booked that way by mistake.': {
    hi: 'जिस आधार पर सामान किराए पर नहीं जाता उसे खाली छोड़ें — ट्रेडमिल घंटे के हिसाब से कोई नहीं लेता, और खाली दर उसे गलती से उस तरह बुक होने से रोकती है।',
    gu: 'જે આધારે સામાન ભાડે જતો નથી તે ખાલી છોડો — ટ્રેડમિલ કલાકના હિસાબે કોઈ લેતું નથી, અને ખાલી દર તેને ભૂલથી એ રીતે બુક થતાં અટકાવે છે.',
  },
  'Held against the kit coming back. Never counted as income until it is kept.': {
    hi: 'सामान वापस आने की गारंटी के तौर पर रखी जाती है। जब तक रख न ली जाए, आय में नहीं गिनी जाती।',
    gu: 'સામાન પાછો આવવાની ખાતરી તરીકે રખાય છે. જ્યાં સુધી રાખી ન લેવાય, આવકમાં ગણાતી નથી.',
  },
  'Late rate (% of normal)': { hi: 'विलंब दर (सामान्य का %)', gu: 'વિલંબ દર (સામાન્યના %)' },
  '150 means a late day costs one and a half.': {
    hi: '150 यानी देरी का एक दिन डेढ़ गुना।',
    gu: '150 એટલે મોડાનો એક દિવસ દોઢ ગણો.',
  },
  'Needs ID to hand over': { hi: 'देने के लिए पहचान आवश्यक', gu: 'આપવા માટે ઓળખ જરૂરી' },
  'The handover refuses without it.': { hi: 'इसके बिना सुपुर्दगी नहीं होगी।', gu: 'તેના વગર સોંપણી થશે નહીં.' },
  'In the fleet': { hi: 'सूची में', gu: 'યાદીમાં' },
  'Off takes it out of every picker.': {
    hi: 'बंद करने पर यह हर सूची से हट जाएगा।',
    gu: 'બંધ કરવાથી એ દરેક યાદીમાંથી નીકળી જશે.',
  },
  'Where it is': { hi: 'कहाँ है', gu: 'ક્યાં છે' },
  'Out on hire is worked out from the contracts — it is not set here.': {
    hi: '"किराए पर बाहर" अनुबंधों से गिना जाता है — यहाँ नहीं लगाया जाता।',
    gu: '"ભાડે બહાર" કરારો પરથી ગણાય છે — અહીં સેટ થતું નથી.',
  },
  'What it cost': { hi: 'कितने में खरीदा', gu: 'કેટલામાં ખરીદ્યું' },
  'Only used to say whether it has earned itself back.': {
    hi: 'केवल यह बताने के लिए कि इसने अपनी कीमत निकाली या नहीं।',
    gu: 'ફક્ત એ કહેવા માટે કે તેણે પોતાની કિંમત વસૂલી કે નહીં.',
  },
  'Bought on': { hi: 'खरीद तिथि', gu: 'ખરીદ તારીખ' },
  'Availability is answered per branch — kit across town is not free here.': {
    hi: 'उपलब्धता शाखा के हिसाब से बताई जाती है — दूसरी शाखा का सामान यहाँ खाली नहीं गिना जाता।',
    gu: 'ઉપલબ્ધતા શાખા પ્રમાણે કહેવાય છે — બીજી શાખાનો સામાન અહીં ખાલી ગણાતો નથી.',
  },
  'Hire kit is not stock. It goes out and comes back, so it lives here with its own rates and condition rather than on the shelf.': {
    hi: 'किराए का सामान स्टॉक नहीं है। वह जाता है और वापस आता है, इसलिए वह अपनी दरों और हालत के साथ यहाँ रहता है, शेल्फ पर नहीं।',
    gu: 'ભાડાનો સામાન સ્ટોક નથી. એ જાય છે અને પાછો આવે છે, એટલે એ પોતાના દર અને સ્થિતિ સાથે અહીં રહે છે, છાજલી પર નહીં.',
  },
  'No hire kit yet. Add a piece and it becomes bookable — the fleet is what the desk hires out, and it is deliberately not stock.': {
    hi: 'अभी कोई किराया सामान नहीं। एक जोड़ें और वह बुक होने लगेगा — यह सूची ही डेस्क किराए पर देती है, और यह जानबूझकर स्टॉक नहीं है।',
    gu: 'હજુ કોઈ ભાડા સામાન નથી. એક ઉમેરો અને એ બુક થવા લાગશે — આ યાદી જ ડેસ્ક ભાડે આપે છે, અને એ ઇરાદાપૂર્વક સ્ટોક નથી.',
  },
  'Kit updated': { hi: 'सामान अद्यतन', gu: 'સામાન અપડેટ' },
  'Kit added to the fleet': { hi: 'सामान सूची में जुड़ा', gu: 'સામાન યાદીમાં ઉમેરાયો' },
  'Could not save the kit': { hi: 'सामान सहेजा नहीं जा सका', gu: 'સામાન સાચવી શકાયો નહીં' },

  // Condition scale — shown on the fleet board and on both legs of a hire.
  'new': { hi: 'नया', gu: 'નવું' },
  'good': { hi: 'ठीक', gu: 'સારું' },
  'fair': { hi: 'चलने लायक', gu: 'ચાલે એવું' },
  'worn': { hi: 'घिसा', gu: 'ઘસાયેલું' },
  'damaged': { hi: 'खराब', gu: 'બગડેલું' },
  'lost': { hi: 'गुम', gu: 'ખોવાયેલું' },

  // ---- Utilisation ----
  'Fleet Utilisation': { hi: 'सामान का उपयोग', gu: 'સામાનનો ઉપયોગ' },
  'How hard each piece of kit is working, and whether it has paid for itself': {
    hi: 'हर सामान कितना काम कर रहा है, और उसने अपनी कीमत निकाली या नहीं',
    gu: 'દરેક સામાન કેટલું કામ કરે છે, અને તેણે પોતાની કિંમત વસૂલી કે નહીં',
  },
  'Last 90 days': { hi: 'पिछले 90 दिन', gu: 'છેલ્લા 90 દિવસ' },
  'Earned in the period': { hi: 'इस अवधि की कमाई', gu: 'આ સમયગાળાની કમાણી' },
  'Hires': { hi: 'कितनी बार गया', gu: 'કેટલી વાર ગયું' },
  'Days out': { hi: 'कितने दिन बाहर', gu: 'કેટલા દિવસ બહાર' },
  'Utilisation': { hi: 'उपयोग', gu: 'ઉપયોગ' },
  'Earned': { hi: 'कमाई', gu: 'કમાણી' },
  'Cost recovered': { hi: 'लागत वसूली', gu: 'ખર્ચ વસૂલાત' },
  'Kit that never went out': { hi: 'जो सामान कभी गया ही नहीं', gu: 'જે સામાન ક્યારેય ગયો જ નહીં' },
  'the rows worth arguing about': { hi: 'यही पंक्तियाँ सोचने लायक हैं', gu: 'આ જ પંક્તિઓ વિચારવા જેવી છે' },
  'Nothing came back inside this period. Utilisation counts settled returns only — a hire still running has not earned anything yet.': {
    hi: 'इस अवधि में कुछ वापस नहीं आया। उपयोग केवल पूरी हुई वापसियों से गिना जाता है — चालू किराए ने अभी कुछ कमाया नहीं है।',
    gu: 'આ સમયગાળામાં કંઈ પાછું આવ્યું નથી. ઉપયોગ ફક્ત પૂરી થયેલી પરત પરથી ગણાય — ચાલુ ભાડાએ હજુ કંઈ કમાયું નથી.',
  },

  // Short rate units, used inline on the fleet board.
  'hr': { hi: 'घंटा', gu: 'કલાક' },
  'day': { hi: 'दिन', gu: 'દિવસ' },
  'wk': { hi: 'सप्ताह', gu: 'અઠવાડિયું' },
  'mo': { hi: 'माह', gu: 'મહિનો' },
  'due': { hi: 'वापसी', gu: 'પરત' },
}

export default phrases
